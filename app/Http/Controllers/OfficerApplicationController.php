<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\OfficerProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// A signed-in user applies to certify animals. An admin checks the licence before approving.
class OfficerApplicationController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'application' => $request->user()->officerProfile?->load('county:id,name'),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $profile = $user->officerProfile;

        // Pending applications wait for review; approved officers are already in
        if ($profile && in_array($profile->status, ['pending', 'approved'], true)) {
            return response()->json(['message' => 'You already have an application in progress.'], 422);
        }

        $data = $request->validate([
            'licence_number' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\/\-\. ]+$/',
                Rule::unique('officer_profiles', 'licence_number')->ignore($profile?->id),
            ],
            'cadre' => ['required', Rule::in(array_keys(OfficerProfile::CADRES))],
            'phone' => ['required', 'string', 'regex:/^(\+?254|0)[17]\d{8}$/'],
            'county_id' => ['required', 'integer', 'exists:counties,id'],
            'organisation' => ['nullable', 'string', 'max:120'],
        ], [
            'phone.regex' => 'Enter a Kenyan mobile number, e.g. 0712 345 678.',
            'licence_number.unique' => 'This licence number is already registered.',
        ]);
        $data['phone'] = preg_replace('/\s+/', '', $data['phone']);

        // First application, or re-applying after a rejection
        $profile ??= new OfficerProfile(['user_id' => $user->id]);
        $profile->fill($data);
        $profile->user_id = $user->id;
        $profile->forceFill(['status' => 'pending', 'review_note' => null, 'verified_at' => null, 'verified_by' => null])->save();

        AuditLog::record('officer.applied', $profile, ['licence' => $profile->licence_number]);

        return response()->json(['application' => $profile->load('county:id,name')], 201);
    }
}
