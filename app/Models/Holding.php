<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// A farm: where animals are kept. Its location is copied onto each animal (see Animal::placeAt).
class Holding extends Model
{
    use HasFactory, HasUuids;

    public const LOCATION_FIELDS = ['county_id', 'sub_county', 'ward', 'village', 'latitude', 'longitude'];

    protected $fillable = [
        'name', 'county_id', 'sub_county', 'ward', 'village',
        'latitude', 'longitude', 'location_accuracy_m', 'location_source', 'milkings_per_day',
    ];

    // The milkings a farm records, by how many times a day it milks
    public const SESSIONS = [1 => ['am'], 2 => ['am', 'pm'], 3 => ['am', 'midday', 'pm']];

    // Same as the column default, so a new farm's API response shows it before any reload
    protected $attributes = ['milkings_per_day' => 2];

    protected $casts = ['latitude' => 'float', 'longitude' => 'float'];

    /** @var array<string, ?string> user id => role on this farm, cached per request */
    private array $roles = [];

    protected static function booted()
    {
        // Whoever creates a farm is its owner member, however it's created (API, seeder, factory)
        static::created(function (Holding $holding) {
            $member = new FarmMember(['role' => FarmMember::OWNER]);
            $member->forceFill(['holding_id' => $holding->id, 'user_id' => $holding->owner_id])->save();
        });
    }

    /** This farm's milking sessions, e.g. ['am', 'pm']. */
    public function sessions(): array
    {
        return self::SESSIONS[$this->milkings_per_day ?? 2] ?? self::SESSIONS[2];
    }

    public function members()
    {
        return $this->hasMany(FarmMember::class);
    }

    /** The user's role on this farm (owner / manager / worker / vet), or null if they're not on its team. */
    public function roleOf(User $user): ?string
    {
        return $this->roles[$user->id] ??= $this->members()->where('user_id', $user->id)->value('role');
    }

    /** Whether the user's role on this farm grants the ability (see FarmMember::ABILITIES). */
    public function allows(User $user, string $ability): bool
    {
        return FarmMember::allows($this->roleOf($user), $ability);
    }

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
