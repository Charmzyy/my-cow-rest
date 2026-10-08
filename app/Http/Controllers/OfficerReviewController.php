<?php

namespace App\Http\Controllers;

use App\Models\CertificationRequest;
use App\Services\CertificateIssuer;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// The officer's side: their queue, the full case file, and the decision.
class OfficerReviewController extends Controller
{
    public function index(Request $request)
    {
        $requests = CertificationRequest::with([
                // latitude/longitude: the queue can sort by distance from the officer
                'animal:id,tag_number,name,sex,county_id,village,latitude,longitude,ai_breed,ai_confidence',
                'animal.county:id,name',
                'animal.photos' => fn ($q) => $q->whereIn('angle', ['left', 'right']),
                'requester:id,fullname',
            ])
            ->where('assigned_officer_id', $request->user()->id)
            ->orderByRaw("FIELD(status, 'assigned', 'in_review', 'needs_info', 'approved', 'rejected', 'cancelled', 'submitted')")
            ->latest('assigned_at')
            ->limit(200)
            ->get();

        return response()->json(['requests' => $requests]);
    }

    public function show(Request $request, CertificationRequest $certificationRequest)
    {
        $this->authorizeOfficer($request, $certificationRequest);

        // Officers are verified professionals: they get the owner's contact details for farm visits
        return response()->json(['request' => $certificationRequest->load([
            'animal.county:id,name', 'animal.holding:id,name', 'animal.photos', 'animal.events', 'requester:id,fullname,email,phone', 'certificate',
        ])]);
    }

    public function start(Request $request, CertificationRequest $certificationRequest)
    {
        $this->authorizeOfficer($request, $certificationRequest);
        if ($certificationRequest->status === 'assigned') {
            $certificationRequest->moveTo('in_review');
        }

        return response()->json(['request' => $certificationRequest]);
    }

    public function approve(Request $request, CertificationRequest $certificationRequest, CertificateIssuer $issuer, Notifier $notify)
    {
        $this->authorizeOfficer($request, $certificationRequest);
        $data = $request->validate([
            'approved_breed' => 'required|string|max:60',
            'purity' => ['required', Rule::in(['purebred', 'crossbreed'])],
            'purity_note' => 'nullable|required_if:purity,crossbreed|string|max:120',
            'inspection_method' => ['required', Rule::in(['photo', 'visit'])],
            'officer_note' => 'nullable|string|max:1000',
        ], ['purity_note.required_if' => 'Describe the cross, e.g. “Friesian × Boran, about 50%”.']);

        // Approve and issue together: never an approval without its certificate, or the reverse
        $certificate = DB::transaction(function () use ($certificationRequest, $data, $issuer) {
            $certificationRequest->moveTo('approved', $data + ['decided_at' => now()], [
                'breed' => $data['approved_breed'], 'purity' => $data['purity'],
            ]);
            return $issuer->issue($certificationRequest);
        });
        $notify->approved($certificationRequest, $certificate);   // after commit: PDF attached

        return response()->json(['request' => $certificationRequest->load('certificate')]);
    }

    public function needsInfo(Request $request, CertificationRequest $certificationRequest, Notifier $notify)
    {
        $this->authorizeOfficer($request, $certificationRequest);
        $data = $request->validate(['officer_note' => 'required|string|max:1000']);

        $certificationRequest->moveTo('needs_info', $data, ['note' => $data['officer_note']]);
        $notify->needsInfo($certificationRequest);

        return response()->json(['request' => $certificationRequest]);
    }

    public function reject(Request $request, CertificationRequest $certificationRequest, Notifier $notify)
    {
        $this->authorizeOfficer($request, $certificationRequest);
        $data = $request->validate(['officer_note' => 'required|string|max:1000']);

        $certificationRequest->moveTo('rejected', $data + ['decided_at' => now()], ['note' => $data['officer_note']]);
        $notify->rejected($certificationRequest);

        return response()->json(['request' => $certificationRequest]);
    }

    private function authorizeOfficer(Request $request, CertificationRequest $r): void
    {
        abort_unless($r->assigned_officer_id === $request->user()->id, 403, 'This request isn’t assigned to you.');
    }
}
