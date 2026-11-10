<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\AnimalEvent;
use Illuminate\Support\Collection;

// Which treatments stop milk (or meat) from being sold, and until when.
// A milking on day d is blocked by a treatment when event_date <= d < milk_clear_on.
class Withdrawal
{
    /**
     * The treatment blocking each cow's milk on $day, keyed by animal_id (the one that clears last).
     *
     * @return Collection<string, AnimalEvent>
     */
    public static function blocking(iterable $animalIds, string $day): Collection
    {
        $ids = collect($animalIds)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return AnimalEvent::whereIn('animal_id', $ids)
            ->whereIn('type', AnimalEvent::HEALTH_TYPES)
            ->where('event_date', '<=', $day)
            ->where('milk_clear_on', '>', $day)
            ->orderBy('milk_clear_on')
            ->get()
            ->keyBy('animal_id');   // ordered ascending, so the last one per cow (latest clear date) wins
    }

    /** For the animal profile: milk / meat not to be sold before these dates (null = nothing pending). */
    public static function statusFor(Animal $animal, ?string $today = null): ?array
    {
        $today ??= FarmClock::today();
        $events = AnimalEvent::where('animal_id', $animal->id)
            ->whereIn('type', AnimalEvent::HEALTH_TYPES)
            ->where('event_date', '<=', $today)
            ->where(fn ($q) => $q->where('milk_clear_on', '>', $today)->orWhere('meat_clear_on', '>', $today))
            ->get();
        if ($events->isEmpty()) {
            return null;
        }

        $milk = $events->filter(fn ($e) => $e->milk_clear_on?->toDateString() > $today)->sortBy('milk_clear_on')->last();
        $meat = $events->filter(fn ($e) => $e->meat_clear_on?->toDateString() > $today)->sortBy('meat_clear_on')->last();

        return [
            'milk_clear_on' => $milk?->milk_clear_on->toDateString(),
            'meat_clear_on' => $meat?->meat_clear_on->toDateString(),
            'product' => ($milk ?? $meat)->product,
        ];
    }
}
