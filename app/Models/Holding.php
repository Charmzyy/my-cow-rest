<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A farm: where animals are kept. Its location is copied onto each animal (see Animal::placeAt).
class Holding extends Model
{
    use HasUuids;

    public const LOCATION_FIELDS = ['county_id', 'sub_county', 'ward', 'village', 'latitude', 'longitude'];

    protected $fillable = [
        'name', 'county_id', 'sub_county', 'ward', 'village',
        'latitude', 'longitude', 'location_accuracy_m', 'location_source',
    ];
    protected $casts = ['latitude' => 'float', 'longitude' => 'float'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function county()
    {
        return $this->belongsTo(County::class);
    }

    public function animals()
    {
        return $this->hasMany(Animal::class);
    }

    /** After the farm moves or is corrected, its animals follow. */
    public function syncAnimals(): void
    {
        $this->animals()->update($this->only(self::LOCATION_FIELDS));
    }
}
