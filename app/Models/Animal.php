<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Animal extends Model
{
    use HasUuids, SoftDeletes;

    // Everything the owner may edit. owner_id, status and the ai_* summary are set by the app only.
    public const SPECIES = ['cattle', 'goat', 'sheep', 'pig', 'camel'];

    protected $fillable = [
        'species', 'tag_number', 'national_id', 'name', 'sex', 'date_of_birth', 'age_estimate_months', 'colour',
        'purpose', 'breed_claimed',
        'sire_name', 'sire_tag', 'dam_name', 'dam_tag', 'ai_straw_code',
        'weight_kg', 'body_condition_score', 'lactation_number', 'milk_litres_per_day',
        'county_id', 'sub_county', 'ward', 'village', 'latitude', 'longitude',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'weight_kg' => 'float',
        'body_condition_score' => 'float',
        'milk_litres_per_day' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
        'ai_confidence' => 'float',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function county()
    {
        return $this->belongsTo(County::class);
    }

    public function holding()
    {
        return $this->belongsTo(Holding::class);
    }

    /** Keep this animal at a farm: it takes the farm's location (county, village, GPS). */
    public function placeAt(Holding $holding): void
    {
        $this->holding_id = $holding->id;
        $this->forceFill($holding->only(Holding::LOCATION_FIELDS));
    }

    public function photos()
    {
        return $this->hasMany(AnimalPhoto::class);
    }

    /** Newest first, as a timeline reads. */
    public function events()
    {
        return $this->hasMany(AnimalEvent::class)->orderByDesc('event_date')->orderByDesc('created_at');
    }

    public function certificationRequests()
    {
        return $this->hasMany(CertificationRequest::class);
    }

    public function latestRequest()
    {
        // By created_at, not id: ids are random UUIDs, so MAX(id) wouldn't be the newest
        return $this->hasOne(CertificationRequest::class)->latestOfMany('created_at');
    }

    /** What's still missing before this animal can be sent for certification (empty = ready). */
    public function certificationProblems(): array
    {
        $angles = $this->photos()->pluck('angle')->all();
        $problems = [];
        if (!in_array('left', $angles, true) || !in_array('right', $angles, true)) {
            $problems[] = 'Add both the left and right side photos.';
        }
        if (!in_array('ear_tag', $angles, true)) {
            $problems[] = 'Add a close-up photo of the ear tag.';
        }
        if (!$this->date_of_birth && $this->age_estimate_months === null) {
            $problems[] = 'Add the date of birth or an age estimate.';
        }
        if (!$this->county_id) {
            $problems[] = 'Add the county.';
        }
        return $problems;
    }

    /**
     * Combine the per-photo AI results into one answer for the animal: the breed with the
     * most total confidence wins; its confidence is the average over the photos that chose it.
     */
    public function refreshAiSummary(): void
    {
        $votes = [];
        $total = 0;
        foreach ($this->photos()->whereIn('angle', AnimalPhoto::AI_ANGLES)->get() as $photo) {
            if (!$photo->ai_breed || $photo->ai_breed === 'Not a cow') {
                continue;
            }
            $total++;
            $votes[$photo->ai_breed][] = $photo->ai_confidence ?? 0;
        }

        if (!$votes) {
            $this->forceFill(['ai_breed' => null, 'ai_confidence' => null, 'ai_photos_agreeing' => null, 'ai_photos_total' => $total ?: null])->save();
            return;
        }

        uasort($votes, fn ($a, $b) => array_sum($b) <=> array_sum($a));
        $breed = array_key_first($votes);

        $this->forceFill([
            'ai_breed' => $breed,
            'ai_confidence' => round(array_sum($votes[$breed]) / count($votes[$breed]), 1),
            'ai_photos_agreeing' => count($votes[$breed]),
            'ai_photos_total' => $total,
        ])->save();
    }
}
