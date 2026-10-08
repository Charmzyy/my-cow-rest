<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CertificationRequest;
use App\Models\OfficerProfile;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Admins verify officer applications (check the licence with the Kenya Veterinary Board first).
class AdminOfficerController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'suspended'])]]);

        // Workload (open_requests) helps admins pick who to assign; counted in the same query
        $officers = OfficerProfile::with(['user:id,fullname,email', 'county:id,name'])
            ->withCount('openRequests as open_requests')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'suspended', 'rejected')")
            ->latest()
            ->get();

        return response()->json(['officers' => $officers]);
    }

    public function approve(Request $request, OfficerProfile $officer, Notifier $notify)
    {
        abort_unless(in_array($officer->status, ['pending', 'suspended'], true), 422, 'Only pending or suspended officers can be approved.');

        DB::transaction(function () use ($request, $officer) {
            $officer->forceFill([
                'status' => 'approved', 'review_note' => null,
                'verified_at' => now(), 'verified_by' => $request->user()->id,
            ])->save();
            $officer->user->forceFill(['role' => User::ROLE_OFFICER])->save();
            AuditLog::record('officer.approved', $officer);
        });
        $notify->officerApproved($officer);

        return response()->json(['officer' => $officer->fresh(['user:id,fullname,email', 'county:id,name'])]);
    }

    public function reject(Request $request, OfficerProfile $officer, Notifier $notify)
    {
        $data = $request->validate(['note' => 'required|string|max:500']);
        abort_unless($officer->status === 'pending', 422, 'Only pending applications can be rejected.');

        $officer->forceFill(['status' => 'rejected', 'review_note' => $data['note']])->save();
        AuditLog::record('officer.rejected', $officer, ['note' => $data['note']]);
        $notify->officerRejected($officer);

        return response()->json(['officer' => $officer->fresh(['user:id,fullname,email', 'county:id,name'])]);
    }

    public function suspend(Request $request, OfficerProfile $officer)
    {
        $data = $request->validate(['note' => 'required|string|max:500']);
        abort_unless($officer->status === 'approved', 422, 'Only approved officers can be suspended.');

        DB::transaction(function () use ($officer, $data) {
            $officer->forceFill(['status' => 'suspended', 'review_note' => $data['note']])->save();
            $officer->user->forceFill(['role' => User::ROLE_FARMER])->save();
            // Their open work goes back to the admin queue for reassignment
            CertificationRequest::where('assigned_officer_id', $officer->user_id)
                ->whereIn('status', ['assigned', 'in_review'])
                ->get()
                ->each(fn ($r) => $r->forceFill(['status' => 'submitted', 'assigned_officer_id' => null, 'assigned_at' => null])->save());
            AuditLog::record('officer.suspended', $officer, ['note' => $data['note']]);
        });

        return response()->json(['officer' => $officer->fresh(['user:id,fullname,email', 'county:id,name'])]);
    }
}
