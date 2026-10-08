<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\CertificationRequest;
use App\Models\User;
use App\Services\CertificateIssuer;
use App\Services\Notifier;
use Illuminate\Http\Request;

// Certificates: public verification, PDF download, and the admin's issue / revoke actions.
class CertificateController extends Controller
{
    /** Public: what the QR code opens. Minimal record, no owner details. */
    public function verify(string $code)
    {
        // People type codes from paper: ignore case, spaces and dashes
        $code = strtoupper(preg_replace('/[\s\-]/', '', $code));
        $certificate = Certificate::where('verification_code', $code)->first();
        abort_unless($certificate, 404, 'No certificate matches this code. Check it and try again.');

        return response()->json(['certificate' => $certificate->publicView()]);
    }

    /** The owner, the certifying officer and admins can download the PDF. */
    public function pdf(Request $request, Certificate $certificate, CertificateIssuer $issuer)
    {
        $user = $request->user();
        abort_unless(
            $certificate->owner_id === $user->id || $certificate->officer_id === $user->id || $user->hasRole(User::ROLE_ADMIN),
            403,
            'Not Authorized'
        );

        return $issuer->pdf($certificate);
    }

    /** Admin: issue the certificate for a request approved before certificates existed. */
    public function issue(CertificationRequest $certificationRequest, CertificateIssuer $issuer, Notifier $notify)
    {
        $isNew = !$certificationRequest->certificate()->exists();
        $certificate = $issuer->issue($certificationRequest);
        if ($isNew) {
            $notify->approved($certificationRequest, $certificate);
        }

        return response()->json(['certificate' => $certificate], 201);
    }

    public function revoke(Request $request, Certificate $certificate, CertificateIssuer $issuer, Notifier $notify)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $issuer->revoke($certificate, $data['reason']);
        $notify->revoked($certificate);

        return response()->json(['certificate' => $certificate]);
    }
}
