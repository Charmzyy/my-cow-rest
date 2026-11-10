<?php

namespace App\Http\Controllers;

use App\Models\AnimalEvent;
use App\Models\Holding;
use App\Support\FarmClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// A farm's health at a glance: which animals' milk / meat can't be sold yet, and which
// vaccinations, dewormings or treatments are due (or overdue).
class HealthController extends Controller
{
    private const DUE_AHEAD_DAYS = 14;
    private const OVERDUE_DAYS = 30;

    public function farm(Request $request, Holding $holding)
    {
        abort_unless($holding->allows($request->user(), 'herd.view'), 403, 'Not Authorized');
        $today = FarmClock::today();

        $animal = fn ($q) => $q->select('id', 'name', 'tag_number')->where('holding_id', $holding->id)->where('status', 'active');

        $withdrawals = AnimalEvent::query()
            ->whereIn('type', AnimalEvent::HEALTH_TYPES)
            ->whereHas('animal', $animal)->with(['animal' => $animal])
            ->where('event_date', '<=', $today)
            ->where(fn ($q) => $q->where('milk_clear_on', '>', $today)->orWhere('meat_clear_on', '>', $today))
            ->get()
            ->groupBy('animal_id')
            ->map(function ($events) use ($today) {
                $milk = $events->filter(fn ($e) => $e->milk_clear_on?->toDateString() > $today)->max('milk_clear_on');
                $meat = $events->filter(fn ($e) => $e->meat_clear_on?->toDateString() > $today)->max('meat_clear_on');
                $a = $events->first()->animal;

                return [
                    'animal' => ['id' => $a->id, 'name' => $a->name, 'tag_number' => $a->tag_number],
                    'product' => $events->sortByDesc('event_date')->first()->product,
                    'milk_clear_on' => $milk?->toDateString(),
                    'meat_clear_on' => $meat?->toDateString(),
                ];
            })
            ->sortBy(fn ($w) => $w['milk_clear_on'] ?? $w['meat_clear_on'])
            ->values();

        // Only each animal's latest record of a kind (same type + product) counts: a later dose means the earlier one was done
        $due = AnimalEvent::query()
            ->whereIn('animal_events.type', AnimalEvent::HEALTH_TYPES)
            ->whereHas('animal', $animal)->with(['animal' => $animal])
            ->whereBetween('animal_events.next_due_on', [
                Carbon::parse($today)->subDays(self::OVERDUE_DAYS)->toDateString(),
                Carbon::parse($today)->addDays(self::DUE_AHEAD_DAYS)->toDateString(),
            ])
            ->whereNotExists(fn ($q) => $q->from('animal_events as later')
                ->whereColumn('later.animal_id', 'animal_events.animal_id')
                ->whereColumn('later.type', 'animal_events.type')
                ->whereRaw("COALESCE(later.product, '') = COALESCE(animal_events.product, '')")
                ->whereColumn('later.event_date', '>', 'animal_events.event_date'))
            ->orderBy('animal_events.next_due_on')
            ->get()
            ->map(fn (AnimalEvent $e) => [
                'event_id' => $e->id,
                'animal' => ['id' => $e->animal->id, 'name' => $e->animal->name, 'tag_number' => $e->animal->tag_number],
                'type' => $e->type,
                'product' => $e->product ?: $e->details,
                'due_on' => $e->next_due_on->toDateString(),
                'overdue' => $e->next_due_on->toDateString() < $today,
            ]);

        return response()->json(['today' => $today, 'withdrawals' => $withdrawals, 'due' => $due]);
    }
}
