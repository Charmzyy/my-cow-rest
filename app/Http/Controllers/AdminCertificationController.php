<?php

namespace App\Http\Controllers;

use App\Models\CertificationRequest;
use App\Models\OfficerProfile;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Admins see every request and assign each one to a verified officer.
class AdminCertificationController extends Controller
{
    // The admin screen's tabs → statuses
    private const TABS = [
        'unassigned' => ['submitted'],
        'progress' => ['assigned', 'in_review', 'needs_info'],
        'decided' => ['approved', 'rejected', 'cancelled'],
    ];

    /**
     * One page of one tab, filtered on the server so it stays fast with thousands of requests.
     * Counts per tab respect the same search / county filter.
     */
    public function index(Request $request)
    {
        $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(self::TABS))],
            'q' => 'nullable|string|max:80',
            'county_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
        ]);
        $tab = $request->input('tab', 'unassigned');

        $filtered = function () use ($request) {
            return CertificationRequest::query()
                ->when($request->county_id, fn ($q, $c) => $q->whereHas('animal', fn ($a) => $a->where('county_id', $c)))
                ->when(trim((string) $request->q), function ($q, $term) {
                    $like = '%' . addcslashes($term, '%_\\') . '%';
                    $q->where(fn ($w) => $w
                        ->whereHas('animal', fn ($a) => $a->where('tag_number', 'like', $like)->orWhere('name', 'like', $like))
                        ->orWhereHas('requester', fn ($u) => $u->where('fullname', 'like', $like)->orWhere('phone', 'like', $like)));
                });
        };

        $byStatus = $filtered()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $counts = array_map(
            fn ($statuses) => (int) collect($statuses)->sum(fn ($s) => $byStatus[$s] ?? 0),
            self::TABS
        );

        $page = $filtered()
            ->with([
                'animal:id,tag_number,name,sex,county_id,ai_breed,ai_confidence',
                'animal.county:id,name',
                'animal.photos' => fn ($q) => $q->whereIn('angle', ['left', 'right']),
                'requester:id,fullname',
                'officer:id,fullname',
                'certificate',
            ])
            ->whereIn('status', self::TABS[$tab])
            // Work queues: first come, first served. Decided: most recent first.
            ->when($tab === 'decided',
                fn ($q) => $q->orderByDesc('updated_at'),
                fn ($q) => $q->orderBy('created_at'))
            ->paginate(25);

        return response()->json([
            'requests' => $page->items(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => $counts,
        ]);
    }

    public function assign(Request $request, CertificationRequest $certificationRequest, Notifier $notify)
    {
        $data = $request->validate(['officer_id' => 'required|uuid']);

        $officer = User::with('officerProfile')->find($data['officer_id']);
        abort_unless($officer && $officer->isApprovedOfficer(), 422, 'Choose a verified officer.');
        abort_if($officer->id === $certificationRequest->requested_by, 422, 'An officer can’t certify their own animal.');

        $certificationRequest->moveTo('assigned', [
            'assigned_officer_id' => $officer->id,
            'assigned_at' => now(),
        ], ['officer_id' => $officer->id]);
        $notify->requestAssigned($certificationRequest);

        return response()->json(['request' => $certificationRequest->load('officer:id,fullname')]);
    }

    /**
     * Assign every waiting request (oldest first, up to 200 per click) to the least busy verified
     * officer in the animal's county. Requests in counties with no officer are left for a manual pick.
     */
    public function autoAssign(Notifier $notify)
    {
        $officers = OfficerProfile::where('status', 'approved')
            ->withCount('openRequests as open_requests')
            ->get();
        abort_if($officers->isEmpty(), 422, 'There are no verified officers yet.');

        $assigned = 0;
        $skipped = 0;
        $waiting = CertificationRequest::with('animal:id,county_id')
            ->where('status', 'submitted')
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        foreach ($waiting as $r) {
            $officer = $officers
                ->filter(fn ($o) => (int) $o->county_id === (int) $r->animal?->county_id && $o->user_id !== $r->requested_by)
                ->sortBy('open_requests')
                ->first();
            if (!$officer) {
                $skipped++;
                continue;
            }

            $r->moveTo('assigned', ['assigned_officer_id' => $officer->user_id, 'assigned_at' => now()], [
                'officer_id' => $officer->user_id, 'auto' => true,
            ]);
            $officer->open_requests++;   // keep the balance right within this run
            $notify->requestAssigned($r);
            $assigned++;
        }

        $message = $assigned ? "Assigned $assigned " . ($assigned === 1 ? 'request' : 'requests') . '.' : 'Nothing could be assigned automatically.';
        if ($skipped) {
            $message .= " $skipped " . ($skipped === 1 ? 'has' : 'have') . ' no verified officer in the same county; assign ' . ($skipped === 1 ? 'it' : 'them') . ' by hand.';
        }

        return response()->json(['assigned' => $assigned, 'skipped' => $skipped, 'message' => $message]);
    }
}
