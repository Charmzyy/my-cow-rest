<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalEvent;
use App\Services\LactationBook;
use App\Services\MilkLedger;
use App\Support\FarmClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// One animal's health / breeding / growth log, kept by everyone on its farm's team.
// Vaccinations, deworming and treatments may carry a product, dose, withdrawal and next-due date;
// a milk withdrawal locks the cow's milk as discarded (MilkLedger).
class AnimalEventController extends Controller
{
    public function index(Animal $animal)
    {
        $this->authorize('view', $animal);

        return response()->json(['events' => $animal->events()->get()]);
    }

    public function store(Request $request, Animal $animal, LactationBook $lactations, MilkLedger $ledger)
    {
        $this->authorize('logEvent', $animal);

        // Offline phones send their own id: a resend (the reply was lost) returns the saved record, never a copy
        $id = $request->validate(['id' => ['nullable', 'uuid']])['id'] ?? null;
        if ($id && ($existing = AnimalEvent::find($id))) {
            abort_unless($existing->animal_id === $animal->id, 409, 'This record was already saved for another animal.');

            return response()->json(['event' => $existing, 'events' => $animal->events()->get()], 200);
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(AnimalEvent::TYPES)],
            // "today" in Kenya: at 01:00 there the server's UTC date is still yesterday
            'event_date' => ['required', 'date', 'before_or_equal:' . FarmClock::today(), 'after:1990-01-01'],
            'details' => ['nullable', 'string', 'max:160'],
            'value' => ['nullable', 'required_if:type,weight', 'numeric', 'between:10,2000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Health records only (ignored for other types)
            'product' => ['nullable', 'string', 'max:80'],
            'dose' => ['nullable', 'string', 'max:40'],
            'course_days' => ['nullable', 'integer', 'between:1,30'],
            'milk_withdrawal_hours' => ['nullable', 'integer', 'between:0,2160'],
            'meat_withdrawal_days' => ['nullable', 'integer', 'between:0,365'],
            'next_due_on' => ['nullable', 'date', 'after:event_date',
                'before_or_equal:' . Carbon::parse(FarmClock::today())->addYears(2)->toDateString()],
            'given_by' => ['nullable', 'string', 'max:80'],
        ], [
            'value.required_if' => 'Enter the weight in kg.',
            'milk_withdrawal_hours.between' => 'Milk withdrawal can be up to 90 days (2160 hours).',
            'next_due_on.after' => 'The next dose must be after this one.',
            'next_due_on.before_or_equal' => 'Set the next dose within two years.',
        ]);
        if ($data['type'] !== 'weight') {
            $data['value'] = null;
        }
        $data['course_days'] ??= 1;

        $event = DB::transaction(function () use ($data, $animal, $request, $ledger, $id) {
            $event = new AnimalEvent($data);   // non-health types drop the health fields on save
            if ($id) {
                $event->id = $id;
            }
            $event->animal_id = $animal->id;
            $event->recorded_by = $request->user()->id;
            $event->save();
            $ledger->applyWithdrawal($event);

            return $event;
        });

        $this->syncWeight($animal);
        if (in_array($event->type, AnimalEvent::LACTATION_TYPES, true)) {
            $lactations->rebuild($animal);
        }

        return response()->json(['event' => $event, 'events' => $animal->events()->get()], 201);
    }

    public function destroy(Request $request, Animal $animal, AnimalEvent $event, LactationBook $lactations, MilkLedger $ledger)
    {
        abort_unless($event->animal_id === $animal->id, 404);
        // Owners and managers may remove any record; anyone else only what they entered themselves
        $user = $request->user();
        abort_unless(
            $user->can('update', $animal) || ($event->recorded_by === $user->id && $user->can('logEvent', $animal)),
            403,
            'Only the farm owner or manager can remove a record someone else entered.'
        );

        DB::transaction(function () use ($event, $ledger) {
            $ledger->releaseWithdrawal($event);
            $event->delete();
        });
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
