<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficerProfile extends Model
{
    use HasFactory, HasUuids;

    public const CADRES = ['vet' => 'Veterinary surgeon', 'para_vet' => 'Veterinary para-professional', 'extension' => 'Livestock extension officer'];

    // status / verification fields are set by admins only
    protected $fillable = ['licence_number', 'cadre', 'phone', 'county_id', 'organisation'];
    protected $casts = ['verified_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Requests assigned to this officer that aren't decided yet (their workload). */
    public function openRequests()
    {
        return $this->hasMany(CertificationRequest::class, 'assigned_officer_id', 'user_id')
            ->whereIn('status', CertificationRequest::OPEN);
    }

    public function county()
    {
        return $this->belongsTo(County::class);
    }
}
