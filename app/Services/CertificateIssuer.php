<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificationRequest;
use App\Models\OfficerProfile;
use App\Support\Qr;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Turns an approved certification request into a numbered certificate, and renders its PDF.
class CertificateIssuer
{
    /** Issue the certificate for an approved request. Safe to call twice: returns the existing one. */
    public function issue(CertificationRequest $request): Certificate
    {
        abort_unless($request->status === 'approved', 422, 'Only approved requests get a certificate.');

        return DB::transaction(function () use ($request) {
            // Lock the request so two calls can't both issue
            CertificationRequest::whereKey($request->id)->lockForUpdate()->first();
            if ($existing = Certificate::where('request_id', $request->id)->first()) {
                return $existing;
            }

            $request->loadMissing(['animal.county', 'requester', 'officer.officerProfile.county']);
            $animal = $request->animal;
            $officer = $request->officer;
            abort_unless($officer, 422, 'This request has no officer.');
            $profile = $officer->officerProfile;

            // Next number for this year. The lock + unique(year, serial) stop two issues getting the same one.
            $year = (int) now()->year;
            $serial = (int) Certificate::where('year', $year)->lockForUpdate()->max('serial') + 1;
            $number = sprintf('MC-KE-%d-%06d', $year, $serial);

            // A re-certified animal has one valid certificate: the newest
            foreach (Certificate::where('animal_id', $animal->id)->where('status', 'valid')->get() as $old) {
                $old->forceFill(['status' => 'superseded'])->save();
                AuditLog::record('certificate.superseded', $old, ['by' => $number]);
            }

            $snapshot = [
                'animal' => [
                    'tag_number' => $animal->tag_number,
                    'national_id' => $animal->national_id,
                    'name' => $animal->name,
                    'sex' => $animal->sex,
                    'date_of_birth' => $animal->date_of_birth?->format('Y-m-d'),
                    'age_estimate_months' => $animal->age_estimate_months,
                    'colour' => $animal->colour,
                    'purpose' => $animal->purpose,
                    'county' => $animal->county?->name,
                    'sub_county' => $animal->sub_county,
                    'ward' => $animal->ward,
                    'village' => $animal->village,
                ],
                'owner' => ['fullname' => $request->requester?->fullname],
                'officer' => [
                    'fullname' => $officer->fullname,
                    'licence_number' => $profile?->licence_number,
                    'cadre' => OfficerProfile::CADRES[$profile?->cadre] ?? null,
                    'county' => $profile?->county?->name,
                ],
                'inspection_method' => $request->inspection_method,
                'officer_note' => $request->officer_note,
                'decided_at' => $request->decided_at?->toIso8601String(),
            ];

            $certificate = new Certificate();
            $certificate->forceFill([
                'certificate_no' => $number,
                'year' => $year,
                'serial' => $serial,
                'verification_code' => $this->newCode(),
                'animal_id' => $animal->id,
                'request_id' => $request->id,
                'owner_id' => $animal->owner_id,
                'officer_id' => $officer->id,
                'breed' => $request->approved_breed,
                'purity' => $request->purity,
                'purity_note' => $request->purity_note,
                'snapshot' => $snapshot,
                'status' => 'valid',
                'issued_at' => now(),
            ]);
            $certificate->content_hash = hash('sha256', json_encode([$number, $certificate->verification_code, $snapshot]));
            $certificate->save();

            AuditLog::record('certificate.issued', $certificate, ['certificate_no' => $number, 'request_id' => $request->id]);

            return $certificate;
        });
    }

    public function revoke(Certificate $certificate, string $reason): Certificate
    {
        abort_unless($certificate->status === 'valid', 422, 'Only a valid certificate can be revoked.');

        $certificate->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by' => auth()->id(),
            'revoke_reason' => $reason,
        ])->save();
        AuditLog::record('certificate.revoked', $certificate, ['reason' => $reason]);

        return $certificate;
    }

    /** The certificate as an A4 PDF (Laravel response that downloads it). */
    public function pdf(Certificate $certificate)
    {
        return $this->build($certificate)->download($certificate->certificate_no . '.pdf');
    }

    /** The PDF bytes, e.g. to attach to an email. */
    public function render(Certificate $certificate): string
    {
        return $this->build($certificate)->output();
    }

    private function build(Certificate $certificate)
    {
        // dompdf caches font metrics here; storage/ is the only folder the app may write to
        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::loadView('certificate', [
            'c' => $certificate,
            's' => $certificate->snapshot,
            'qr' => Qr::runs($certificate->verify_url),
            'photo' => $this->photoDataUri($certificate),
        ])->setPaper('a4');
    }

    /** Side photo embedded in the PDF. JPEG only: dompdf needs the GD extension for PNG. */
    private function photoDataUri(Certificate $certificate): ?string
    {
        $path = $certificate->animal?->photos()->whereIn('angle', ['left', 'right'])->orderBy('angle')->value('path');
        $file = $path ? storage_path('app/public/' . $path) : null;
        if (!$file || !is_file($file) || !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode(file_get_contents($file));
    }

    private function newCode(): string
    {
        do {
            $code = Qr::randomCode(10);
        } while (Certificate::where('verification_code', $code)->exists());

        return $code;
    }
}
