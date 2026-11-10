<?php

namespace App\Services;

use App\Models\AnimalEvent;
use App\Models\Holding;
use App\Models\MilkLog;
use App\Models\User;
use App\Support\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The only way milk logs are written. Keeps milk_daily (per cow per day) in step, inside the same
// transaction, so charts and reports never disagree with the logs. Also enforces treatment
// withdrawal: a milking inside a milk withdrawal is always stored as discarded.
class MilkLedger
{
    /**
     * Save one milking session for a farm. Each entry: animal_id, litres (null = clear it), discarded, id (optional).
     * Re-sending the same session updates the cows' logs in place: never duplicates.
     *
     * @return array{saved: int, cleared: int}
     */
    public function recordSession(Holding $holding, string $day, string $session, array $entries, User $by): array
    {
        $saved = $cleared = 0;

        DB::transaction(function () use ($holding, $day, $session, $entries, $by, &$saved, &$cleared) {
            $blocked = Withdrawal::blocking(array_column($entries, 'animal_id'), $day);

            foreach ($entries as $entry) {
                $log = MilkLog::where('animal_id', $entry['animal_id'])
                    ->where('milked_on', $day)
                    ->where('session', $session)
                    ->lockForUpdate()
                    ->first();

                if ($entry['litres'] === null) {
                    if ($log) {
                        $log->delete();
                        $cleared++;
                    }
                } else {
                    $log ??= (new MilkLog())->forceFill([
                        // the phone's id when given (offline sync), so a resent batch matches the first one
                        'id' => $entry['id'] ?? (string) Str::uuid(),
                        'animal_id' => $entry['animal_id'],
                        'milked_on' => $day,
                        'session' => $session,
                    ]);
                    $treatment = $blocked[$entry['animal_id']] ?? null;
                    $log->forceFill([
                        'holding_id' => $holding->id,
                        'litres' => $entry['litres'],
                        // under withdrawal: discarded whatever the phone sent
                        'discarded' => $treatment ? true : (bool) ($entry['discarded'] ?? false),
                        'withdrawal_event_id' => $treatment?->id,
                        'recorded_by' => $by->id,
                    ])->save();
                    $saved++;
                }

                $this->refreshDay($entry['animal_id'], $day);
            }
        });

        return ['saved' => $saved, 'cleared' => $cleared];
    }

    /** A treatment was recorded (possibly late): lock the milkings already logged inside its milk withdrawal. */
    public function applyWithdrawal(AnimalEvent $event): int
    {
        if (!$event->milk_clear_on) {
            return 0;
        }

        return DB::transaction(function () use ($event) {
            $logs = MilkLog::where('animal_id', $event->animal_id)
                ->where('milked_on', '>=', $event->event_date->toDateString())
                ->where('milked_on', '<', $event->milk_clear_on->toDateString())
                ->lockForUpdate()
                ->get();

            foreach ($logs as $log) {
                // Discarded by hand (sick, colostrum): already not sold, and must stay so if this treatment is deleted
                if ($log->discarded && !$log->withdrawal_event_id) {
                    continue;
                }
                // Keep a link to the treatment that clears last, so deleting the shorter one changes nothing
                $current = $log->withdrawal_event_id ? AnimalEvent::find($log->withdrawal_event_id) : null;
                if ($current && $current->milk_clear_on >= $event->milk_clear_on) {
                    continue;
                }
                $log->forceFill(['discarded' => true, 'withdrawal_event_id' => $event->id])->save();
            }
            foreach ($logs->pluck('milked_on')->map->toDateString()->unique() as $day) {
                $this->refreshDay($event->animal_id, $day);
            }

            return $logs->count();
        });
    }

    /**
     * A treatment is about to be deleted: release the milkings it locked, unless another treatment
     * still covers them. Milk the user had marked discarded by hand (no treatment link) is left alone.
     */
    public function releaseWithdrawal(AnimalEvent $event): int
    {
        return DB::transaction(function () use ($event) {
            $logs = MilkLog::where('withdrawal_event_id', $event->id)->lockForUpdate()->get();

            foreach ($logs as $log) {
                $day = $log->milked_on->toDateString();
                // Another treatment of this cow that also covers this day keeps it locked
                $other = AnimalEvent::where('animal_id', $log->animal_id)->whereKeyNot($event->id)
                    ->whereIn('type', AnimalEvent::HEALTH_TYPES)
                    ->where('event_date', '<=', $day)->where('milk_clear_on', '>', $day)
                    ->orderByDesc('milk_clear_on')->first();

                $log->forceFill(['discarded' => (bool) $other, 'withdrawal_event_id' => $other?->id])->save();
            }
            foreach ($logs->map(fn ($l) => [$l->animal_id, $l->milked_on->toDateString()])->unique(fn ($p) => implode('|', $p)) as [$animalId, $day]) {
                $this->refreshDay($animalId, $day);
            }

            return $logs->count();
        });
    }

    /** Recompute one cow's daily row from its logs (removes the row when nothing is left). */
    public function refreshDay(string $animalId, string $day): void
    {
        // Plain query builder: the model's casts would turn the discarded total into true/false
        $totals = DB::table('milk_logs')->where('animal_id', $animalId)->where('milked_on', $day)
            ->selectRaw('SUM(litres) AS total, SUM(CASE WHEN discarded THEN litres ELSE 0 END) AS not_sellable, COUNT(*) AS n, MAX(holding_id) AS farm')
            ->first();

        $key = ['animal_id' => $animalId, 'day' => $day];
        if (!$totals || (int) $totals->n === 0) {
            DB::table('milk_daily')->where($key)->delete();
            return;
        }

        DB::table('milk_daily')->updateOrInsert($key, [
            'holding_id' => $totals->farm,
            'litres' => $totals->total,
            'discarded' => $totals->not_sellable,
            'sessions' => $totals->n,
        ]);
    }
}
