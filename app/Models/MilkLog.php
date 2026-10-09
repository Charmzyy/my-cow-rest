<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// One cow, one milking. Written through MilkLedger, which keeps milk_daily in step.
class MilkLog extends Model
{
    use HasUuids;

    public const SESSIONS = ['am', 'midday', 'pm'];

    protected $fillable = ['litres', 'discarded'];
    protected $casts = ['milked_on' => 'date:Y-m-d', 'litres' => 'float', 'discarded' => 'boolean'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
