<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AuditLog;
use App\Models\CertificationRequest;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// The farmer's side: ask for an animal to be certified, cancel, or resubmit after "needs info".
class CertificationRequestController extends Controller
{
    public function store(Request $request, Animal $animal, Notifier $notify)
    {
        $this->authorize('certify', $animal);

        $data = $request->validate([
            'inspection_method' => ['required', Rule::in(['photo', 'visit'])],
            'owner_note' => 'nullable|string|max:500',
        ]);

        if ($problems = $animal->certificationProblems()) {
            return response()->json(['message' => 'This animal isn’t ready yet.', 'problems' => $problems], 422);
        }

        // Lock the animal row so a double tap can't create two open requests
        $certRequest = DB::transaction(function () use ($animal, $data, $request) {
            Animal::whereKey($animal->id)->lockForUpdate()->first();
            abort_if(
                $animal->certificationRequests()->whereIn('status', CertificationRequest::OPEN)->exists(),
                422,
                'This animal already has a certification request in progress.'
            );

            $r = new CertificationRequest($data);
            $r->animal_id = $animal->id;
            $r->requested_by = $request->user()->id;
            $r->status = 'submitted';
            $r->save();
            AuditLog::record('request.submitted', $r, $data);
            return $r;
        });
        $notify->requestReceived($certRequest);

        return response()->json(['request' => $certRequest], 201);
    }

    public function cancel(Request $request, CertificationRequest $certificationRequest)
    {
        $this->authorizeOwner($request, $certificationRequest);
        $certificationRequest->moveTo('cancelled');

        return response()->json(['request' => $certificationRequest]);
    }

    public function resubmit(Request $request, CertificationRequest $certificationRequest, Notifier $notify)
    {
        $this->authorizeOwner($request, $certificationRequest);
        abort_unless($certificationRequest->status === 'needs_info', 422, 'Only requests waiting for more information can be resubmitted.');

        $data = $request->validate(['owner_note' => 'nullable|string|max:500']);
        if ($problems = $certificationRequest->animal->certificationProblems()) {
            return response()->json(['message' => 'This animal isn’t ready yet.', 'problems' => $problems], 422);
        }

        // Back to the same officer, who already knows the case
        $certificationRequest->moveTo('assigned', ['owner_note' => $data['owner_note'] ?? $certificationRequest->owner_note]);
        $notify->resubmitted($certificationRequest);

        return response()->json(['request' => $certificationRequest]);
    }

    private function authorizeOwner(Request $request, CertificationRequest $r): void
    {
        abort_unless($r->requested_by === $request->user()->id, 403, 'Not Authorized');
    }
}
