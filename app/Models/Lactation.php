<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use App\Support\FarmClock;

// One calving to drying off. Never edited directly: rebuilt from the animal's calving / dry-off
// records by LactationBook, so fixing a record fixes the lactations too.
class Lactation extends Model
{
    use HasUuids;

    protected $guarded = [];
    protected $casts = ['calved_on' => 'date:Y-m-d', 'dried_on' => 'date:Y-m-d'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function isOpen(): bool
    {
        return $this->dried_on === null;
    }

    /** Days since calving (to drying off, if it has ended). */
    public function daysInMilk(): int
    {
        // Compare dates in Kenya time: in the evening the server's UTC date is still yesterday
        return (int) $this->calved_on->diffInDays($this->dried_on ?? Carbon::parse(FarmClock::today()));
    }
}
