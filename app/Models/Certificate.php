<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    use HasUuids;

    // Everything is set by CertificateIssuer / revoke(); nothing is mass assignable
    protected $guarded = ['*'];
    protected $hidden = ['snapshot', 'content_hash'];
    protected $casts = [
        'snapshot' => 'array',
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
    protected $appends = ['verify_url'];

    public function animal()
    {
        return $this->belongsTo(Animal::class)->withTrashed();
    }

    public function request()
    {
        return $this->belongsTo(CertificationRequest::class, 'request_id');
    }

    public function officer()
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    /** The public page the QR code opens (the Vue app, not the API). */
    public function getVerifyUrlAttribute(): string
    {
        return rtrim(config('app.frontend_url'), '/') . '/verify/' . $this->verification_code;
    }

    /** Anyone holding the code may see this. No owner name or contact details, ever. */
    public function publicView(): array
    {
        $s = $this->snapshot;
        $photo = $this->animal?->photos()->whereIn('angle', ['left', 'right'])->orderBy('angle')->value('path');

        return [
            'certificate_no' => $this->certificate_no,
            'status' => $this->status,
            'issued_at' => $this->issued_at,
            'revoked_at' => $this->revoked_at,
            'breed' => $this->breed,
            'purity' => $this->purity,
            'purity_note' => $this->purity_note,
            'inspection_method' => $s['inspection_method'] ?? null,
            'animal' => array_intersect_key($s['animal'], array_flip([
                'tag_number', 'national_id', 'name', 'sex', 'date_of_birth', 'age_estimate_months', 'colour', 'county',
            ])),
            'officer' => $s['officer'],
            'photo' => $this->status === 'valid' ? $photo : null,
        ];
    }
}
