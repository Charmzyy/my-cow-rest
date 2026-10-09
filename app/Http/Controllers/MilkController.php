<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Holding;
use App\Models\MilkDelivery;
use App\Models\MilkLog;
use App\Services\MilkLedger;
use App\Support\FarmClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Milk recording: the milking-session sheet (pre-filled list of the farm's cows), saving it,
// and the numbers behind the cow and farm charts.
class MilkController extends Controller
{
    /** How far back a milking may be entered or corrected. */
    private const BACKFILL_DAYS = 90;

    /** The sheet for one session: every female on the farm, cows in milk first, with today's entry and the last one as a hint. */
    public function session(Request $request, Holding $holding)
    {
        $this->authorizeFarm($request, $holding, 'herd.view');
        $data = $request->validate([
            'day' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . FarmClock::today()],
            'session' => ['nullable', Rule::in($holding->sessions())],
        ]);

        return response()->json($this->sheet(
            $holding,
            $data['day'] ?? FarmClock::today(),
            $data['session'] ?? FarmClock::currentSession($holding->sessions()),
        ));
    }

    /** Save the sheet. Only the cows sent are touched; litres = null clears that cow's entry. Safe to resend. */
    public function record(Request $request, Holding $holding, MilkLedger $ledger)
    {
        $this->authorizeFarm($request, $holding, 'milk.write');
        $data = $request->validate([
            'day' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . FarmClock::today(),
                'after_or_equal:' . FarmClock::now()->subDays(self::BACKFILL_DAYS)->toDateString()],
            'session' => ['required', Rule::in($holding->sessions())],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.animal_id' => ['required', 'uuid', 'distinct'],
            'entries.*.id' => ['nullable', 'uuid'],
            'entries.*.litres' => ['present', 'nullable', 'numeric', 'between:0,60'],
            'entries.*.discarded' => ['nullable', 'boolean'],
        ], [
            'day.after_or_equal' => 'Milk can only be entered for the last ' . self::BACKFILL_DAYS . ' days.',
            'session.in' => 'This farm doesn’t record that milking. Change “milkings per day” under Farms.',
            'entries.*.litres.between' => 'Enter 0 to 60 litres per milking.',
        ]);

        $ids = collect($data['entries'])->pluck('animal_id');
        $onFarm = Animal::where('holding_id', $holding->id)->whereIn('id', $ids)->count();
        if ($onFarm !== $ids->count()) {
            throw ValidationException::withMessages(['entries' => 'Some of these animals aren’t on this farm. Reload the list and try again.']);
        }

        $result = $ledger->recordSession($holding, $data['day'], $data['session'], $data['entries'], $request->user());

        return response()->json($result + $this->sheet($holding, $data['day'], $data['session']));
    }

    /** One cow: daily litres for the chart, her lactations, and the latest milkings. */
    public function cow(Request $request, Animal $animal)
    {
        $this->authorize('view', $animal);
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'between:7,365']])['days'] ?? 30);
        $from = FarmClock::now()->subDays($days - 1)->toDateString();

        $daily = DB::table('milk_daily')->where('animal_id', $animal->id)->where('day', '>=', $from)
            ->get(['day', 'litres', 'discarded'])->keyBy('day');

        $lactations = $animal->lactations->map(function ($l) use ($animal) {
            $range = DB::table('milk_daily')->where('animal_id', $animal->id)
                ->where('day', '>=', $l->calved_on->toDateString())
                ->when($l->dried_on, fn ($q) => $q->where('day', '<=', $l->dried_on->toDateString()));

            return [
                'number' => $l->number,
                'calved_on' => $l->calved_on->toDateString(),
                'dried_on' => $l->dried_on?->toDateString(),
                'days_in_milk' => $l->daysInMilk(),
                'total_litres' => round((float) (clone $range)->sum('litres'), 1),
                'peak_litres' => round((float) (clone $range)->max('litres'), 1),
            ];
        })->reverse()->values();

        return response()->json([
            'status' => $animal->milkStatus(),
            'series' => $this->fillDays($from, $days, fn ($day) => [
                'litres' => round((float) ($daily[$day]->litres ?? 0), 2),
                'discarded' => round((float) ($daily[$day]->discarded ?? 0), 2),
            ]),
            'lactations' => $lactations,
            'recent' => MilkLog::where('animal_id', $animal->id)->orderByDesc('milked_on')->orderByDesc('session')->limit(10)
                ->get(['id', 'milked_on', 'session', 'litres', 'discarded']),
        ]);
    }

    /** The farm: milk produced vs where it went, day by day, plus the best cows. Money only for owners and managers. */
    public function summary(Request $request, Holding $holding)
    {
        $this->authorizeFarm($request, $holding, 'herd.view');
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'between:7,365']])['days'] ?? 30);
        $from = FarmClock::now()->subDays($days - 1)->toDateString();
        $showMoney = $holding->allows($request->user(), 'sales.manage');

        $produced = DB::table('milk_daily')->where('holding_id', $holding->id)->where('day', '>=', $from)
            ->groupBy('day')->selectRaw('day, SUM(litres) AS litres, SUM(discarded) AS discarded')->get()->keyBy('day');

        $out = DB::table('milk_deliveries')->where('holding_id', $holding->id)->where('day', '>=', $from)
            ->groupBy('day', 'kind')
            ->selectRaw('day, kind, SUM(litres) AS litres, SUM(rejected_litres) AS rejected, SUM((litres - rejected_litres) * price_per_litre) AS amount')
            ->get()->groupBy('day');

        $series = $this->fillDays($from, $days, function ($day) use ($produced, $out) {
            $row = ['produced' => round((float) ($produced[$day]->litres ?? 0), 2), 'discarded' => round((float) ($produced[$day]->discarded ?? 0), 2), 'rejected' => 0.0];
            foreach (MilkDelivery::KINDS as $kind) {
                $row[$kind] = 0.0;
            }
            foreach ($out[$day] ?? [] as $o) {
                $row[$o->kind] = round((float) $o->litres, 2);
                $row['rejected'] += round((float) $o->rejected, 2);
            }

            return $row;
        });

        $totals = ['produced' => 0.0, 'discarded' => 0.0, 'rejected' => 0.0] + array_fill_keys(MilkDelivery::KINDS, 0.0);
        foreach ($series as $row) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $row[$k];
            }
        }
        $totals = array_map(fn ($v) => round($v, 1), $totals);

        if ($showMoney) {
            $money = DB::table('milk_deliveries')->where('holding_id', $holding->id)->where('day', '>=', $from)
                ->whereIn('kind', MilkDelivery::SOLD)->whereNotNull('price_per_litre')
                ->selectRaw('SUM((litres - rejected_litres) * price_per_litre) AS income, SUM(CASE WHEN paid THEN 0 ELSE (litres - rejected_litres) * price_per_litre END) AS unpaid')
                ->first();
            $totals['income'] = round((float) $money->income, 2);
            $totals['unpaid'] = round((float) $money->unpaid, 2);
        }

        $cows = DB::table('milk_daily')->join('animals', 'animals.id', '=', 'milk_daily.animal_id')
            ->where('milk_daily.holding_id', $holding->id)->where('milk_daily.day', '>=', $from)
            ->groupBy('animals.id', 'animals.name', 'animals.tag_number')
            ->selectRaw('animals.id, animals.name, animals.tag_number, SUM(milk_daily.litres) AS litres, COUNT(*) AS days')
            ->orderByDesc('litres')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'tag_number' => $c->tag_number,
                'litres' => round((float) $c->litres, 1), 'per_day' => round($c->litres / max(1, $c->days), 1),
            ]);

        return response()->json([
            'days' => $days,
            'series' => $series,
            'totals' => $totals,
            'cows' => $cows,
            'shows_money' => $showMoney,
        ]);
    }

    private function sheet(Holding $holding, string $day, string $session): array
    {
        $cows = $holding->animals()->where('sex', 'female')->where('status', 'active')
            ->with('currentLactation')
            ->orderBy('name')->orderBy('tag_number')
            ->get(['id', 'name', 'tag_number', 'qr_code', 'holding_id']);
        $ids = $cows->pluck('id');

        $logs = MilkLog::whereIn('animal_id', $ids)->where('milked_on', $day)->where('session', $session)->get()->keyBy('animal_id');
        // The last time each cow was milked at this session, as a hint for typing (two weeks back is plenty)
        $last = MilkLog::whereIn('animal_id', $ids)->where('session', $session)
            ->where('milked_on', '<', $day)->where('milked_on', '>=', Carbon::parse($day)->subDays(14)->toDateString())
            ->orderBy('milked_on')->get(['animal_id', 'litres'])->groupBy('animal_id')->map(fn ($g) => $g->last()->litres);

        $order = ['milking' => 0, 'unknown' => 1, 'dry' => 2];
        $rows = $cows->map(function (Animal $a) use ($logs, $last) {
            $log = $logs[$a->id] ?? null;

            return [
                'id' => $a->id,
                'name' => $a->name,
                'tag_number' => $a->tag_number,
                'qr_code' => $a->qr_code,
                'status' => $a->milkStatus(),
                'days_in_milk' => $a->currentLactation?->isOpen() ? $a->currentLactation->daysInMilk() : null,
                'last_litres' => $last[$a->id] ?? null,
                'log' => $log ? ['id' => $log->id, 'litres' => $log->litres, 'discarded' => $log->discarded] : null,
            ];
        })->sortBy(fn ($r) => $order[$r['status']])->values();

        return [
            'farm' => ['id' => $holding->id, 'name' => $holding->name, 'milkings_per_day' => $holding->milkings_per_day],
            'day' => $day,
            'session' => $session,
            'sessions' => $holding->sessions(),
            'today' => FarmClock::today(),
            'cows' => $rows,
            'total' => round($rows->sum(fn ($r) => $r['log']['litres'] ?? 0), 2),
        ];
    }

    /** One entry per day from $from, oldest first, so charts get a continuous axis (missing days = 0). */
    private function fillDays(string $from, int $days, callable $row): array
    {
        $out = [];
        $day = Carbon::parse($from);
        for ($i = 0; $i < $days; $i++, $day->addDay()) {
            $d = $day->toDateString();
            $out[] = ['day' => $d] + $row($d);
        }

        return $out;
    }

    private function authorizeFarm(Request $request, Holding $holding, string $ability): void
    {
        abort_unless($holding->allows($request->user(), $ability), 403, 'Not Authorized');
    }
}
