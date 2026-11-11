<?php

namespace App\Http\Controllers;

use App\Models\AnimalEvent;
use App\Models\Holding;
use App\Models\MilkDelivery;
use App\Support\FarmClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// One endpoint behind every farm chart (ChartCard.vue): pick a metric, how to group it,
// a date range and a bucket size. Days are Kenya days (FarmClock), and empty days, weeks or
// months come back as 0 so the time axis has no gaps.
//
// Answer: { metric, group, kind: time|category, unit, labels[], series[{ key, label, values[] }],
//           from, to, granularity, dated }
class AnalyticsController extends Controller
{
    public const GRANULARITIES = ['day', 'week', 'month'];
    public const CHART_TYPES = ['bar', 'stacked', 'line', 'area', 'pie', 'doughnut'];
    public const MAX_DAYS = 731;        // two years
    public const MAX_CATEGORIES = 12;   // the rest is summed into "Other"

    // group => 'time' or 'category'. A pie only makes sense for a category (ChartCard enforces it).
    public const METRICS = [
        'milk.produced' => ['unit' => 'l', 'groups' => ['time' => 'time', 'cow' => 'category', 'session' => 'category']],
        'milk.outlets' => ['unit' => 'l', 'groups' => ['time' => 'time', 'outlet' => 'category']],
        'milk.income' => ['unit' => 'KES', 'groups' => ['time' => 'time', 'buyer' => 'category'], 'money' => true],
        'herd.composition' => ['unit' => 'animals', 'groups' => ['breed' => 'category', 'sex' => 'category', 'age' => 'category', 'species' => 'category'], 'undated' => true],
        'health.events' => ['unit' => 'records', 'groups' => ['time' => 'time', 'type' => 'category']],
    ];

    private const SESSION_LABELS = ['am' => 'Morning', 'midday' => 'Midday', 'pm' => 'Evening'];
    private const OUTLET_LABELS = ['coop' => 'Co-op / dairy', 'sale' => 'Local sale', 'home' => 'Home use', 'calves' => 'Fed to calves', 'rejected' => 'Rejected at co-op'];
    private const EVENT_LABELS = [
        'vaccination' => 'Vaccination', 'deworming' => 'Deworming', 'treatment' => 'Treatment', 'ai_service' => 'AI / service',
        'calving' => 'Calving', 'dry_off' => 'Dried off', 'heat' => 'Heat', 'pregnancy_check' => 'Pregnancy check', 'other' => 'Other',
    ];

    public function holding(Request $request, Holding $holding)
    {
        abort_unless($holding->allows($request->user(), 'herd.view'), 403, 'Not Authorized');

        $data = $request->validate([
            'metric' => ['required', Rule::in(array_keys(self::METRICS))],
            'group' => ['required', 'string'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'granularity' => ['nullable', Rule::in(self::GRANULARITIES)],
        ]);
        $spec = self::METRICS[$data['metric']];
        if (!isset($spec['groups'][$data['group']])) {
            throw ValidationException::withMessages(['group' => 'This chart can’t be grouped that way.']);
        }
        if (!empty($spec['money']) && !$holding->allows($request->user(), 'sales.manage')) {
            abort(403, 'Only the farm’s owner and managers can see money.');
        }

        [$from, $to] = $this->range($data);
        $granularity = $data['granularity'] ?? 'day';
        $kind = $spec['groups'][$data['group']];

        $args = [$holding, $data['group'], $from, $to, $granularity];
        $result = match ($data['metric']) {
            'milk.produced' => $this->milkProduced(...$args),
            'milk.outlets' => $this->milkOutlets(...$args),
            'milk.income' => $this->milkIncome(...$args),
            'herd.composition' => $this->herdComposition(...$args),
            'health.events' => $this->healthEvents(...$args),
        };

        return response()->json([
            'metric' => $data['metric'],
            'group' => $data['group'],
            'kind' => $kind,
            'unit' => $spec['unit'],
            'dated' => empty($spec['undated']),
            'from' => $from,
            'to' => $to,
            'granularity' => $granularity,
        ] + $result);
    }

    /** Default: the last 30 Kenya days. Never in the future, never more than two years. */
    private function range(array $data): array
    {
        $today = FarmClock::today();
        $to = $data['to'] ?? $today;
        $from = $data['from'] ?? Carbon::parse($to)->subDays(29)->toDateString();

        $errors = [];
        if ($to > $today) {
            $errors['to'] = 'The range can’t end in the future.';
        } elseif ($from > $to) {
            $errors['from'] = 'The start date must be before the end date.';
        } elseif (Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > self::MAX_DAYS) {
            $errors['from'] = 'Pick a range of two years or less.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [$from, $to];
    }

    // ---- Metrics -------------------------------------------------------------------------------

    private function milkProduced(Holding $holding, string $group, string $from, string $to, string $granularity): array
    {
        if ($group === 'time') {
            $rows = DB::table('milk_daily')->where('holding_id', $holding->id)->whereBetween('day', [$from, $to])
                ->groupBy('day')->selectRaw('day, SUM(litres - discarded) AS kept, SUM(discarded) AS discarded')->get();

            return $this->timeSeries($rows, $from, $to, $granularity, ['kept' => 'Usable milk', 'discarded' => 'Discarded']);
        }

        if ($group === 'session') {
            $rows = DB::table('milk_logs')->where('holding_id', $holding->id)->whereBetween('milked_on', [$from, $to])
                ->groupBy('session')->selectRaw('session AS k, SUM(litres) AS v')->pluck('v', 'k');
            $values = [];
            foreach ($holding->sessions() as $s) {
                $values[self::SESSION_LABELS[$s]] = (float) ($rows[$s] ?? 0);
            }

            return $this->categories($values, 'Litres', false);
        }

        // cow
        $rows = DB::table('milk_daily')->join('animals', 'animals.id', '=', 'milk_daily.animal_id')
            ->where('milk_daily.holding_id', $holding->id)->whereBetween('milk_daily.day', [$from, $to])
            ->groupBy('animals.id', 'animals.name', 'animals.tag_number')
            ->selectRaw('animals.name, animals.tag_number, SUM(milk_daily.litres) AS v')
            ->orderByDesc('v')->get();

        return $this->categories($rows->mapWithKeys(fn ($r) => [($r->name ?: "Tag {$r->tag_number}") . ($r->name ? " ({$r->tag_number})" : '') => (float) $r->v])->all(), 'Litres');
    }

    private function milkOutlets(Holding $holding, string $group, string $from, string $to, string $granularity): array
    {
        $base = DB::table('milk_deliveries')->where('holding_id', $holding->id)->whereBetween('day', [$from, $to]);
        $sums = 'SUM(CASE WHEN kind = ? THEN litres - rejected_litres ELSE 0 END)';

        if ($group === 'time') {
            $rows = (clone $base)->groupBy('day')->selectRaw(
                "day, $sums AS coop, $sums AS sale, $sums AS home, $sums AS calves, SUM(rejected_litres) AS rejected",
                ['coop', 'sale', 'home', 'calves']
            )->get();

            return $this->timeSeries($rows, $from, $to, $granularity, self::OUTLET_LABELS);
        }

        $row = (clone $base)->selectRaw(
            "$sums AS coop, $sums AS sale, $sums AS home, $sums AS calves, SUM(rejected_litres) AS rejected",
            ['coop', 'sale', 'home', 'calves']
        )->first();
        $values = [];
        foreach (self::OUTLET_LABELS as $key => $label) {
            $values[$label] = (float) ($row->{$key} ?? 0);
        }

        return $this->categories($values, 'Litres', false);
    }

    private function milkIncome(Holding $holding, string $group, string $from, string $to, string $granularity): array
    {
        // Same rule as MilkController::summary: sold kinds with a price, minus what the co-op rejected
        $base = DB::table('milk_deliveries')->where('milk_deliveries.holding_id', $holding->id)
            ->whereBetween('milk_deliveries.day', [$from, $to])
            ->whereIn('milk_deliveries.kind', MilkDelivery::SOLD)->whereNotNull('milk_deliveries.price_per_litre');
        $amount = '(milk_deliveries.litres - milk_deliveries.rejected_litres) * milk_deliveries.price_per_litre';

        if ($group === 'time') {
            $rows = (clone $base)->groupBy('milk_deliveries.day')->selectRaw(
                "milk_deliveries.day AS day, SUM(CASE WHEN milk_deliveries.paid THEN $amount ELSE 0 END) AS paid,
                 SUM(CASE WHEN milk_deliveries.paid THEN 0 ELSE $amount END) AS unpaid"
            )->get();

            return $this->timeSeries($rows, $from, $to, $granularity, ['paid' => 'Paid', 'unpaid' => 'Not yet paid']);
        }

        // buyer
        $rows = (clone $base)->leftJoin('milk_buyers', 'milk_buyers.id', '=', 'milk_deliveries.buyer_id')
            ->groupBy('milk_buyers.name', 'milk_deliveries.kind')
            ->selectRaw("milk_buyers.name AS name, milk_deliveries.kind AS kind, SUM($amount) AS v")
            ->orderByDesc('v')->get();
        $values = [];
        foreach ($rows as $r) {
            $label = $r->name ?: ($r->kind === 'coop' ? 'Co-op (no buyer chosen)' : 'Local sales');
            $values[$label] = ($values[$label] ?? 0) + (float) $r->v;
        }
        arsort($values);

        return $this->categories($values, 'KES');
    }

    /** The herd as it is today: the date range doesn't apply (`dated: false`). */
    private function herdComposition(Holding $holding, string $group, string $from, string $to, string $granularity): array
    {
        $animals = DB::table('animals')->where('holding_id', $holding->id)->where('status', 'active')->whereNull('deleted_at');

        if ($group === 'age') {
            $bands = ['Under 1 year' => 0, '1–2 years' => 0, '2–5 years' => 0, '5 years +' => 0, 'Age unknown' => 0];
            $now = FarmClock::now();
            foreach ($animals->get(['date_of_birth', 'age_estimate_months', 'created_at']) as $a) {
                $months = $a->date_of_birth
                    ? Carbon::parse($a->date_of_birth)->diffInMonths($now)
                    : ($a->age_estimate_months !== null ? $a->age_estimate_months + Carbon::parse($a->created_at)->diffInMonths($now) : null);
                $band = match (true) {
                    $months === null => 'Age unknown',
                    $months < 12 => 'Under 1 year',
                    $months < 24 => '1–2 years',
                    $months < 60 => '2–5 years',
                    default => '5 years +',
                };
                $bands[$band]++;
            }

            return $this->categories(array_filter($bands), 'Animals', false);
        }

        $column = match ($group) {
            'breed' => "COALESCE(NULLIF(breed_claimed, ''), ai_breed, 'Unknown')",
            'sex' => 'sex',
            default => 'species',
        };
        $rows = $animals->groupByRaw($column)->selectRaw("$column AS k, COUNT(*) AS v")->orderByDesc('v')->pluck('v', 'k');
        $label = fn ($k) => $group === 'breed' ? $k : ucfirst($k);

        return $this->categories($rows->mapWithKeys(fn ($v, $k) => [$label($k) => (int) $v])->all(), 'Animals');
    }

    private function healthEvents(Holding $holding, string $group, string $from, string $to, string $granularity): array
    {
        $base = DB::table('animal_events')->join('animals', 'animals.id', '=', 'animal_events.animal_id')
            ->where('animals.holding_id', $holding->id)
            ->whereBetween('animal_events.event_date', [$from, $to])
            ->where('animal_events.type', '!=', 'weight');   // weighings are growth, not health or breeding

        if ($group === 'time') {
            $rows = (clone $base)->groupBy('animal_events.event_date')
                ->selectRaw('animal_events.event_date AS day, SUM(CASE WHEN animal_events.type IN (?, ?, ?) THEN 1 ELSE 0 END) AS health, SUM(CASE WHEN animal_events.type IN (?, ?, ?, ?, ?) THEN 1 ELSE 0 END) AS breeding, SUM(CASE WHEN animal_events.type = ? THEN 1 ELSE 0 END) AS other',
                    [...AnimalEvent::HEALTH_TYPES, 'ai_service', 'calving', 'dry_off', 'heat', 'pregnancy_check', 'other'])
                ->get();

            return $this->timeSeries($rows, $from, $to, $granularity, ['health' => 'Health', 'breeding' => 'Breeding', 'other' => 'Other']);
        }

        $rows = (clone $base)->groupBy('animal_events.type')->selectRaw('animal_events.type AS k, COUNT(*) AS v')->orderByDesc('v')->pluck('v', 'k');

        return $this->categories($rows->mapWithKeys(fn ($v, $k) => [self::EVENT_LABELS[$k] ?? ucfirst($k) => (int) $v])->all(), 'Records');
    }

    // ---- Shaping -------------------------------------------------------------------------------

    /**
     * Rows with a `day` column and one column per series -> one value per bucket for each series.
     * Series that are 0 everywhere are dropped, so a stacked chart has no empty legend entries.
     */
    private function timeSeries($rows, string $from, string $to, string $granularity, array $seriesLabels): array
    {
        $buckets = FarmClock::buckets($from, $to, $granularity);
        $index = array_flip($buckets);
        $values = array_map(fn () => array_fill(0, count($buckets), 0.0), $seriesLabels);

        foreach ($rows as $row) {
            $i = $index[FarmClock::bucket((string) $row->day, $granularity)] ?? null;
            if ($i === null) {
                continue;
            }
            foreach ($seriesLabels as $key => $_) {
                $values[$key][$i] += (float) ($row->{$key} ?? 0);
            }
        }

        $series = [];
        foreach ($seriesLabels as $key => $label) {
            $rounded = array_map(fn ($v) => round($v, 2), $values[$key]);
            if (array_sum($rounded) != 0 || count($seriesLabels) === 1) {
                $series[] = ['key' => $key, 'label' => $label, 'values' => $rounded];
            }
        }

        return ['labels' => $buckets, 'series' => $series];
    }

    /**
     * label => value -> one series. With $fold, the biggest MAX_CATEGORIES - 1 stay and the rest
     * become "Other"; zero rows are dropped. Without it, the order given is kept (e.g. Morning, Evening).
     */
    private function categories(array $values, string $seriesLabel, bool $fold = true): array
    {
        if ($fold) {
            $values = array_filter($values, fn ($v) => $v != 0);
            arsort($values);
            if (count($values) > self::MAX_CATEGORIES) {
                $kept = array_slice($values, 0, self::MAX_CATEGORIES - 1, true);
                $kept['Other'] = array_sum(array_slice($values, self::MAX_CATEGORIES - 1));
                $values = $kept;
            }
        }

        return [
            'labels' => array_map('strval', array_keys($values)),
            'series' => [['key' => 'value', 'label' => $seriesLabel, 'values' => array_map(fn ($v) => round((float) $v, 2), array_values($values))]],
        ];
    }
}
