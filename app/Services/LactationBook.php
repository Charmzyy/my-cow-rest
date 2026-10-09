<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Lactation;
use Illuminate\Support\Facades\DB;

// Lactations are derived, never typed in: each calving record starts one, and the first dry-off
// record after it (before the next calving) ends it. Rebuilt from scratch whenever a calving or
// dry-off record is added or removed, so a corrected record always gives correct lactations.
class LactationBook
{
    public function rebuild(Animal $animal): void
    {
        $calvings = $animal->events()->reorder()->where('type', 'calving')->orderBy('event_date')->pluck('event_date')->unique()->values();
        $dryOffs = $animal->events()->reorder()->where('type', 'dry_off')->orderBy('event_date')->pluck('event_date');

        DB::transaction(function () use ($animal, $calvings, $dryOffs) {
            Lactation::where('animal_id', $animal->id)->delete();

            foreach ($calvings as $i => $calvedOn) {
                $next = $calvings[$i + 1] ?? null;
                $driedOn = $dryOffs->first(fn ($d) => $d->gte($calvedOn) && (!$next || $d->lt($next)));

                Lactation::create([
                    'animal_id' => $animal->id,
                    'number' => $i + 1,
                    'calved_on' => $calvedOn,
                    'dried_on' => $driedOn,
                ]);
            }
        });

        $animal->unsetRelation('currentLactation')->unsetRelation('lactations');
    }
}
