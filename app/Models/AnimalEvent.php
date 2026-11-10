<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AnimalEvent extends Model
{
    use HasUuids;

    public const TYPES = ['vaccination', 'deworming', 'treatment', 'ai_service', 'calving', 'dry_off', 'heat', 'pregnancy_check', 'weight', 'other'];

    // Records that start or end a lactation (LactationBook rebuilds lactations when these change)
    public const LACTATION_TYPES = ['calving', 'dry_off'];

    // Records that may carry a product, dose, withdrawal and next-due date
    public const HEALTH_TYPES = ['vaccination', 'deworming', 'treatment'];
    public const HEALTH_FIELDS = ['product', 'dose', 'course_days', 'milk_withdrawal_hours', 'meat_withdrawal_days', 'next_due_on', 'given_by'];

    protected $fillable = [
        'type', 'event_date', 'details', 'value', 'notes',
        'product', 'dose', 'course_days', 'milk_withdrawal_hours', 'meat_withdrawal_days', 'next_due_on', 'given_by',
    ];

    protected $attributes = ['course_days' => 1];

    protected $casts = [
        'event_date' => 'date:Y-m-d',
        'value' => 'float',
        'course_days' => 'integer',
        'milk_withdrawal_hours' => 'integer',
        'meat_withdrawal_days' => 'integer',
        'milk_clear_on' => 'date:Y-m-d',
        'meat_clear_on' => 'date:Y-m-d',
        'next_due_on' => 'date:Y-m-d',
    ];

    protected static function booted()
    {
        static::saving(fn (AnimalEvent $event) => $event->computeClearDates());
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function isHealth(): bool
    {
        return in_array($this->type, self::HEALTH_TYPES, true);
    }

    /**
     * Work out the first day milk / meat may be sold again, erring on the safe side:
     * counted from the LAST dose of the course, plus one whole day because the time of
     * the dose isn't recorded (a 72 h withdrawal from an evening dose runs into day 4).
     */
    public function computeClearDates(): void
    {
        if (!$this->isHealth()) {
            foreach (self::HEALTH_FIELDS as $field) {
                $this->{$field} = null;
            }
            $this->course_days = 1;
            $this->milk_clear_on = $this->meat_clear_on = null;

            return;
        }

        $lastDose = Carbon::parse($this->event_date)->addDays(max(1, (int) $this->course_days) - 1);

        $this->milk_clear_on = $this->milk_withdrawal_hours
            ? $lastDose->copy()->addDays((int) ceil($this->milk_withdrawal_hours / 24) + 1)->toDateString()
            : null;
        $this->meat_clear_on = $this->meat_withdrawal_days
            ? $lastDose->copy()->addDays($this->meat_withdrawal_days + 1)->toDateString()
            : null;
    }
}
