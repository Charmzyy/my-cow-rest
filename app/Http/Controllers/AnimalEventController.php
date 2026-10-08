<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// The owner's health / breeding / growth log for one animal.
class AnimalEventController extends Controller
{
    public function index(Animal $animal)
    {
        $this->authorize('view', $animal);

        return response()->json(['events' => $animal->events()->get()]);
    }

    public function store(Request $request, Animal $animal)
    {
        $this->authorize('update', $animal);

        $data = $request->validate([
            'type' => ['required', Rule::in(AnimalEvent::TYPES)],
            'event_date' => ['required', 'date', 'before_or_equal:today', 'after:1990-01-01'],
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

        return response()->json(['event' => $event, 'events' => $animal->events()->get()], 201);
    }

    public function destroy(Animal $animal, AnimalEvent $event)
    {
        $this->authorize('update', $animal);
        abort_unless($event->animal_id === $animal->id, 404);

        $event->delete();
        $this->syncWeight($animal);

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
