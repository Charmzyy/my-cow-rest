<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AnimalEvent extends Model
{
    use HasUuids;

    public const TYPES = ['vaccination', 'deworming', 'treatment', 'ai_service', 'calving', 'weight', 'other'];

    protected $fillable = ['type', 'event_date', 'details', 'value', 'notes'];
    protected $casts = ['event_date' => 'date:Y-m-d', 'value' => 'float'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
