<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Two people entered different milk for the same cow and milking (usually both offline).
// The latest typed entry was kept; this records the one that lost so an owner can check it.
class MilkClash extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'milked_on' => 'date:Y-m-d',
        'kept_litres' => 'float',
        'lost_litres' => 'float',
        'kept_discarded' => 'boolean',
        'lost_discarded' => 'boolean',
        'kept_at' => 'datetime',
        'lost_at' => 'datetime',
        'seen_at' => 'datetime',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    // Not keptBy/lostBy: in JSON those would collide with the kept_by / lost_by id columns
    public function keptByUser()
    {
        return $this->belongsTo(User::class, 'kept_by');
    }

    public function lostByUser()
    {
        return $this->belongsTo(User::class, 'lost_by');
    }
}
