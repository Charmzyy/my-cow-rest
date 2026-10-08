<?php

namespace App\Services;

use App\Mail\NoticeMail;
use App\Models\Animal;
use App\Models\Certificate;
use App\Models\CertificationRequest;
use App\Models\OfficerProfile;
use Illuminate\Support\Facades\Mail;

// Every workflow email in one place. Call these AFTER the change is saved (outside transactions):
// a mail outage is logged, never turned into an error for the person who clicked.
class Notifier
{
    public function __construct(private CertificateIssuer $issuer)
    {
    }

    // ---- Farmer ----------------------------------------------------------------------------

    public function requestReceived(CertificationRequest $r): void
    {
        $r->loadMissing('animal.county', 'requester');
        $this->send($r->requester?->email, new NoticeMail(
            'Certification request received',
            'We’ve received your certification request',
            [
                "Thank you, {$this->first($r->requester?->fullname)}. A verified officer will be assigned to {$this->animalName($r->animal)} shortly.",
                'You’ll get an email at each step. You can still add photos or fix details while you wait.',
            ],
            $this->animalFacts($r->animal) + ['Inspection' => $this->method($r)],
            ['label' => 'View your animal', 'url' => $this->link("/herd/{$r->animal_id}")],
        ));
    }

    public function needsInfo(CertificationRequest $r): void
    {
        $r->loadMissing('animal', 'requester', 'officer');
        $this->send($r->requester?->email, new NoticeMail(
            'More information needed for ' . $this->animalName($r->animal),
            'The officer needs a little more information',
            [
                "{$r->officer?->fullname} is reviewing {$this->animalName($r->animal)} and asks:",
                '“' . $r->officer_note . '”',
                'Update the record or photos, then press Resubmit on the animal’s page.',
            ],
            [],
            ['label' => 'Update and resubmit', 'url' => $this->link("/herd/{$r->animal_id}")],
        ));
    }

    public function approved(CertificationRequest $r, Certificate $certificate): void
    {
        $r->loadMissing('animal', 'requester');
        try {
            $pdf = $this->issuer->render($certificate);
        } catch (\Throwable $e) {
            report($e);
            $pdf = null; // still tell them; they can download it from the app
        }

        $this->send($r->requester?->email, new NoticeMail(
            "Certified: {$this->animalName($r->animal)} ({$certificate->certificate_no})",
            'Your animal is certified',
            [
                "{$this->animalName($r->animal)} has been certified by a registered officer." . ($pdf ? ' The certificate is attached as a PDF.' : ' Download the certificate from the app.'),
                'Anyone can check it is genuine by scanning its QR code. Share the certificate with buyers, your co-operative or your lender.',
            ],
            [
                'Certificate no.' => $certificate->certificate_no,
                'Breed' => $certificate->breed,
                'Purity' => $certificate->purity === 'purebred' ? 'Purebred' : trim('Crossbreed ' . ($certificate->purity_note ? "· {$certificate->purity_note}" : '')),
                'Verification code' => $certificate->verification_code,
                'Officer’s remarks' => $r->officer_note,
            ],
            ['label' => 'View the certificate', 'url' => $this->link("/herd/{$r->animal_id}")],
            $pdf,
            $certificate->certificate_no . '.pdf',
        ));
    }

    public function rejected(CertificationRequest $r): void
    {
        $r->loadMissing('animal', 'requester');
        $this->send($r->requester?->email, new NoticeMail(
            'Certification not approved: ' . $this->animalName($r->animal),
            'Your certification request was not approved',
            [
                "The officer could not certify {$this->animalName($r->animal)}. Their reason:",
                '“' . $r->officer_note . '”',
                'If you can fix this (for example clearer photos or a corrected record), you can request certification again.',
            ],
            [],
            ['label' => 'View your animal', 'url' => $this->link("/herd/{$r->animal_id}")],
        ));
    }

    public function revoked(Certificate $certificate): void
    {
        $certificate->loadMissing('animal.owner');
        $this->send($certificate->animal?->owner?->email, new NoticeMail(
            "Certificate {$certificate->certificate_no} has been revoked",
            'A certificate has been revoked',
            [
                "Certificate {$certificate->certificate_no} for {$this->animalName($certificate->animal)} is no longer valid. Its verification page now shows it as revoked.",
                'Reason: ' . $certificate->revoke_reason,
                'You can request certification again from the animal’s page.',
            ],
            [],
            ['label' => 'View your animal', 'url' => $this->link("/herd/{$certificate->animal_id}")],
        ));
    }

    // ---- Officer ---------------------------------------------------------------------------

    public function requestAssigned(CertificationRequest $r): void
    {
        $r->loadMissing('animal.county', 'requester', 'officer');
        $this->send($r->officer?->email, new NoticeMail(
            'New animal to certify: ' . $this->animalName($r->animal),
            'A certification request has been assigned to you',
            ["Please review {$this->animalName($r->animal)} for {$r->requester?->fullname}."],
            $this->animalFacts($r->animal) + ['Inspection' => $this->method($r), 'Farmer’s note' => $r->owner_note],
            ['label' => 'Open the case', 'url' => $this->link("/officer/requests/{$r->id}")],
        ));
    }

    public function resubmitted(CertificationRequest $r): void
    {
        $r->loadMissing('animal', 'requester', 'officer');
        $this->send($r->officer?->email, new NoticeMail(
            'Resubmitted: ' . $this->animalName($r->animal),
            'The farmer has sent the request back',
            array_values(array_filter([
                "{$r->requester?->fullname} has updated {$this->animalName($r->animal)} after your question.",
                $r->owner_note ? 'Their note: “' . $r->owner_note . '”' : null,
            ])),
            [],
            ['label' => 'Continue the review', 'url' => $this->link("/officer/requests/{$r->id}")],
        ));
    }

    public function officerApproved(OfficerProfile $p): void
    {
        $p->loadMissing('user');
        $this->send($p->user?->email, new NoticeMail(
            'You’re now a verified MyCow officer',
            'Your officer application is approved',
            [
                "Welcome, {$this->first($p->user?->fullname)}. Your licence {$p->licence_number} has been verified.",
                'Sign in again to see your review queue. Certification requests will be assigned to you by the MyCow team.',
            ],
            [],
            ['label' => 'Open my queue', 'url' => $this->link('/officer')],
        ));
    }

    public function officerRejected(OfficerProfile $p): void
    {
        $p->loadMissing('user');
        $this->send($p->user?->email, new NoticeMail(
            'Your MyCow officer application',
            'We couldn’t approve your officer application',
            ['Reason: ' . $p->review_note, 'You can correct your details and apply again.'],
            [],
            ['label' => 'Update my application', 'url' => $this->link('/become-officer')],
        ));
    }

    // ---- Helpers ---------------------------------------------------------------------------

    private function send(?string $email, NoticeMail $mail): void
    {
        if (!$email) {
            return;
        }
        try {
            Mail::to($email)->send($mail);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function link(string $path): string
    {
        return rtrim(config('app.frontend_url'), '/') . $path;
    }

    private function animalName(?Animal $a): string
    {
        if (!$a) {
            return 'your animal';
        }
        return $a->name ? "{$a->name} (tag {$a->tag_number})" : "tag {$a->tag_number}";
    }

    private function animalFacts(?Animal $a): array
    {
        return $a ? [
            'Ear tag' => $a->tag_number,
            'Name' => $a->name,
            'Sex' => ucfirst($a->sex),
            'County' => $a->county?->name,
        ] : [];
    }

    private function method(CertificationRequest $r): string
    {
        return $r->inspection_method === 'visit' ? 'Farm visit' : 'Photo review';
    }

    private function first(?string $name): string
    {
        return explode(' ', trim((string) $name))[0] ?: 'there';
    }
}
