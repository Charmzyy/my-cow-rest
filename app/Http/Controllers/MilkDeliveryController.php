<?php

namespace App\Http\Controllers;

use App\Models\Holding;
use App\Models\MilkBuyer;
use App\Models\MilkDelivery;
use App\Support\FarmClock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Where the milk went: co-op deliveries, local sales, home use, calves. Workers record them
// (they're often the ones taking milk to the collection point); owners and managers set buyers
// and prices, mark payments and can correct anyone's entry.
class MilkDeliveryController extends Controller
{
    public function index(Request $request, Holding $holding)
    {
        $this->authorizeFarm($request, $holding, 'milk.write');
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = $data['to'] ?? FarmClock::today();
        $from = $data['from'] ?? $to;

        $deliveries = MilkDelivery::where('holding_id', $holding->id)
            ->whereBetween('day', [$from, $to])
            ->with('buyer:id,name,kind')
            ->orderByDesc('day')->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json(['deliveries' => $deliveries, 'from' => $from, 'to' => $to]);
    }

    public function store(Request $request, Holding $holding)
    {
        $this->authorizeFarm($request, $holding, 'milk.write');
        $data = $this->validated($request, $holding);

        $delivery = new MilkDelivery($data);
        $delivery->forceFill(['holding_id' => $holding->id, 'recorded_by' => $request->user()->id])->save();

        return response()->json(['delivery' => $delivery->load('buyer:id,name,kind')], 201);
    }

    public function update(Request $request, Holding $holding, MilkDelivery $delivery)
    {
        $this->authorizeEntry($request, $holding, $delivery);
        $delivery->update($this->validated($request, $holding));

        return response()->json(['delivery' => $delivery->load('buyer:id,name,kind')]);
    }

    public function destroy(Request $request, Holding $holding, MilkDelivery $delivery)
    {
        $this->authorizeEntry($request, $holding, $delivery);
        $delivery->delete();

        return response()->json(['message' => 'Removed']);
    }

    // ---- Buyers -------------------------------------------------------------------------------

    public function buyers(Request $request, Holding $holding)
    {
        $this->authorizeFarm($request, $holding, 'milk.write');

        return response()->json(['buyers' => MilkBuyer::where('holding_id', $holding->id)->orderByDesc('active')->orderBy('name')->get()]);
    }

    public function storeBuyer(Request $request, Holding $holding)
    {
        $this->authorizeFarm($request, $holding, 'sales.manage');

        $buyer = new MilkBuyer($this->buyerRules($request));
        $buyer->holding_id = $holding->id;
        $buyer->save();

        return response()->json(['buyer' => $buyer], 201);
    }

    public function updateBuyer(Request $request, Holding $holding, MilkBuyer $buyer)
    {
        $this->authorizeFarm($request, $holding, 'sales.manage');
        abort_unless($buyer->holding_id === $holding->id, 404);

        $buyer->update($this->buyerRules($request));

        return response()->json(['buyer' => $buyer]);
    }

    // -------------------------------------------------------------------------------------------

    private function validated(Request $request, Holding $holding): array
    {
        $data = $request->validate([
            'day' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . FarmClock::today(),
                'after_or_equal:' . FarmClock::now()->subDays(90)->toDateString()],
            'session' => ['nullable', Rule::in($holding->sessions())],
            'kind' => ['required', Rule::in(MilkDelivery::KINDS)],
            'buyer_id' => ['nullable', 'uuid', Rule::exists('milk_buyers', 'id')->where('holding_id', $holding->id)],
            'litres' => ['required', 'numeric', 'gt:0', 'max:5000'],
            'rejected_litres' => ['nullable', 'numeric', 'min:0', 'lte:litres'],
            'price_per_litre' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'paid' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:160'],
        ], [
            'rejected_litres.lte' => 'Rejected milk can’t be more than what was delivered.',
            'buyer_id.exists' => 'Choose one of this farm’s buyers.',
        ]);

        $sold = in_array($data['kind'], MilkDelivery::SOLD, true);
        if (!$sold) {
            // Home use and calves: no buyer, no money, nothing rejected
            $data = array_merge($data, ['buyer_id' => null, 'price_per_litre' => null, 'rejected_litres' => 0, 'paid' => false]);
        } elseif (($data['price_per_litre'] ?? null) === null && !empty($data['buyer_id'])) {
            // The buyer's usual price, when none was typed
            $data['price_per_litre'] = MilkBuyer::whereKey($data['buyer_id'])->value('price_per_litre');
        }
        $data['rejected_litres'] ??= 0;
        $data['paid'] = (bool) ($data['paid'] ?? false);

        return $data;
    }

    private function buyerRules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(MilkBuyer::KINDS)],
            'price_per_litre' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'active' => ['nullable', 'boolean'],
        ]);
    }

    /** Owners / managers may change any entry; others only what they entered. */
    private function authorizeEntry(Request $request, Holding $holding, MilkDelivery $delivery): void
    {
        abort_unless($delivery->holding_id === $holding->id, 404);
        $user = $request->user();
        abort_unless(
            $holding->allows($user, 'sales.manage') || ($delivery->recorded_by === $user->id && $holding->allows($user, 'milk.write')),
            403,
            'Only the owner or a manager can change an entry someone else made.'
        );
    }

    private function authorizeFarm(Request $request, Holding $holding, string $ability): void
    {
        abort_unless($holding->allows($request->user(), $ability), 403, 'Not Authorized');
    }
}
