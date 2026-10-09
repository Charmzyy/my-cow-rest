<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\MilkLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The only way milk logs are written. Keeps milk_daily (per cow per day) in step, inside the same
// transaction, so charts and reports never disagree with the logs.
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
                    $log->forceFill([
                        'holding_id' => $holding->id,
                        'litres' => $entry['litres'],
                        'discarded' => (bool) ($entry['discarded'] ?? false),
                        'recorded_by' => $by->id,
                    ])->save();
                    $saved++;
                }

                $this->refreshDay($entry['animal_id'], $day);
            }
        });

        return ['saved' => $saved, 'cleared' => $cleared];
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
