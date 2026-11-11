<?php

namespace App\Services;

use App\Models\AnimalEvent;
use App\Models\Holding;
use App\Models\MilkClash;
use App\Models\MilkLog;
use App\Models\User;
use App\Support\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The only way milk logs are written. Keeps milk_daily (per cow per day) in step, inside the same
// transaction, so charts and reports never disagree with the logs. Also enforces treatment
// withdrawal: a milking inside a milk withdrawal is always stored as discarded.
class MilkLedger
{
    /**
     * Save one milking session for a farm. Each entry: animal_id, litres (null = clear it), discarded,
     * id (optional), entered_at (optional: when it was typed on the phone; default now).
     * Re-sending the same session updates the cows' logs in place: never duplicates.
     *
     * Offline phones sync late and in any order, so the entry TYPED last wins: one older than what
     * is already saved is ignored ("stale"). When two different people disagreed, the losing entry
     * is kept in milk_clashes for the owner to check. One person correcting their own entry is not a clash.
     *
     * @return array{saved: int, cleared: int, stale: int}
     */
    public function recordSession(Holding $holding, string $day, string $session, array $entries, User $by): array
    {
        $saved = $cleared = $stale = 0;

        DB::transaction(function () use ($holding, $day, $session, $entries, $by, &$saved, &$cleared, &$stale) {
            $blocked = Withdrawal::blocking(array_column($entries, 'animal_id'), $day);

            foreach ($entries as $entry) {
                $log = MilkLog::where('animal_id', $entry['animal_id'])
                    ->where('milked_on', $day)
                    ->where('session', $session)
                    ->lockForUpdate()
                    ->first();

                $treatment = $blocked[$entry['animal_id']] ?? null;
                $enteredAt = $this->enteredAt($entry['entered_at'] ?? null);
                $incoming = [
                    'litres' => $entry['litres'] === null ? null : (float) $entry['litres'],
                    // under withdrawal: discarded whatever the phone sent
                    'discarded' => $entry['litres'] !== null && ($treatment || (bool) ($entry['discarded'] ?? false)),
                ];

                if ($log && $log->entered_at && $enteredAt->lt($log->entered_at)) {
                    // Someone typed a newer entry for this milking already: keep it
                    $this->noteClash($holding, $log, $by, $incoming, $enteredAt, keptIncoming: false);
                    $stale++;
                    continue;
                }
                if ($log) {
                    $this->noteClash($holding, $log, $by, $incoming, $enteredAt, keptIncoming: true);
                }

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
                    $log->forceFill([
                        'holding_id' => $holding->id,
                        'litres' => $incoming['litres'],
                        'discarded' => $incoming['discarded'],
                        'withdrawal_event_id' => $treatment?->id,
                        'recorded_by' => $by->id,
                        'entered_at' => $enteredAt,
                    ])->save();
                    $saved++;
                }

                $this->refreshDay($entry['animal_id'], $day);
            }
        });

        return ['saved' => $saved, 'cleared' => $cleared, 'stale' => $stale];
    }

    /** The phone's typing time, never later than now (a phone clock can be wrong). */
    private function enteredAt(?string $value): Carbon
    {
        $now = Carbon::now();
        if (!$value) {
            return $now;
        }
        $at = Carbon::parse($value);

        return $at->gt($now) ? $now : $at;
    }

    /** Different people, different numbers for the same milking: keep the losing entry for the owner to see. */
    private function noteClash(Holding $holding, MilkLog $existing, User $by, array $incoming, Carbon $enteredAt, bool $keptIncoming): void
    {
        $same = $existing->recorded_by === $by->id;
        $agree = $incoming['litres'] !== null
            && abs($existing->litres - $incoming['litres']) < 0.005
            && $existing->discarded === $incoming['discarded'];
        if ($same || $agree) {
            return;
        }

        $old = ['litres' => $existing->litres, 'discarded' => $existing->discarded, 'by' => $existing->recorded_by, 'at' => $existing->entered_at ?? $existing->updated_at];
        $new = ['litres' => $incoming['litres'], 'discarded' => $incoming['discarded'], 'by' => $by->id, 'at' => $enteredAt];
        [$kept, $lost] = $keptIncoming ? [$new, $old] : [$old, $new];

        MilkClash::create([
            'holding_id' => $holding->id,
            'animal_id' => $existing->animal_id,
            'milked_on' => $existing->milked_on->toDateString(),
            'session' => $existing->session,
            'kept_litres' => $kept['litres'], 'kept_discarded' => $kept['discarded'], 'kept_by' => $kept['by'], 'kept_at' => $kept['at'],
            'lost_litres' => $lost['litres'], 'lost_discarded' => $lost['discarded'], 'lost_by' => $lost['by'], 'lost_at' => $lost['at'],
        ]);
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
