<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalEvent;
use App\Services\LactationBook;
use App\Support\FarmClock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// One animal's health / breeding / growth log, kept by everyone on its farm's team.
class AnimalEventController extends Controller
{
    public function index(Animal $animal)
    {
        $this->authorize('view', $animal);

        return response()->json(['events' => $animal->events()->get()]);
    }

    public function store(Request $request, Animal $animal, LactationBook $lactations)
    {
        $this->authorize('logEvent', $animal);

        $data = $request->validate([
            'type' => ['required', Rule::in(AnimalEvent::TYPES)],
            // "today" in Kenya: at 01:00 there the server's UTC date is still yesterday
            'event_date' => ['required', 'date', 'before_or_equal:' . FarmClock::today(), 'after:1990-01-01'],
            'details' => ['nullable', 'string', 'max:160'],
            'value' => ['nullable', 'required_if:type,weight', 'numeric', 'between:10,2000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['value.required_if' => 'Enter the weight in kg.']);
        if ($data['type'] !== 'weight') {
            $data['value'] = null;
        }

        $event = new AnimalEvent($data);
        $event->animal_id = $animal->id;
        $event->recorded_by = $request->user()->id;
        $event->save();

        $this->syncWeight($animal);
        if (in_array($event->type, AnimalEvent::LACTATION_TYPES, true)) {
            $lactations->rebuild($animal);
        }

        return response()->json(['event' => $event, 'events' => $animal->events()->get()], 201);
    }

    public function destroy(Request $request, Animal $animal, AnimalEvent $event, LactationBook $lactations)
    {
        abort_unless($event->animal_id === $animal->id, 404);
        // Owners and managers may remove any record; anyone else only what they entered themselves
        $user = $request->user();
        abort_unless(
            $user->can('update', $animal) || ($event->recorded_by === $user->id && $user->can('logEvent', $animal)),
            403,
            'Only the farm owner or manager can remove a record someone else entered.'
        );

        $event->delete();
        $this->syncWeight($animal);
        if (in_array($event->type, AnimalEvent::LACTATION_TYPES, true)) {
            $lactations->rebuild($animal);
        }

        return response()->json(['events' => $animal->events()->get()]);
    }

    /** The profile's "Weight" shows the most recent weighing, when there is one. */
    private function syncWeight(Animal $animal): void
    {
        $latest = $animal->events()->where('type', 'weight')->reorder()->orderByDesc('event_date')->orderByDesc('created_at')->value('value');
        if ($latest !== null) {
            $animal->forceFill(['weight_kg' => $latest])->save();
        }
    }
}
