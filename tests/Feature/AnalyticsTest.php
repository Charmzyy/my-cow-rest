<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\FarmMember;
use App\Models\Holding;
use App\Models\User;
use App\Support\FarmClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The reports endpoint behind every farm chart: totals agree with the milk summary, empty
// days are 0, buckets are Kenya weeks and months, and money stays with owners and managers.
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Holding $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->farm = Holding::factory()->for($this->owner, 'owner')->create(['milkings_per_day' => 2]);
        Sanctum::actingAs($this->owner);
    }

    private function day(int $daysAgo = 0): string
    {
        return FarmClock::now()->subDays($daysAgo)->toDateString();
    }

    private function cow(array $attrs = []): Animal
    {
        return Animal::factory()->for($this->farm)->create($attrs);
    }

    private function milk(Animal $cow, string $session, float $litres, int $daysAgo = 0, bool $discarded = false): void
    {
        $this->postJson("/api/holdings/{$this->farm->id}/milk/session", [
            'day' => $this->day($daysAgo), 'session' => $session,
            'entries' => [['animal_id' => $cow->id, 'litres' => $litres, 'discarded' => $discarded]],
        ])->assertOk();
    }

    private function chart(array $query)
    {
        return $this->getJson("/api/holdings/{$this->farm->id}/analytics?" . http_build_query($query));
    }

    private function addWorker(): User
    {
        $worker = User::factory()->worker()->create();
        $member = new FarmMember(['role' => FarmMember::WORKER]);
        $member->forceFill(['holding_id' => $this->farm->id, 'user_id' => $worker->id])->save();

        return $worker;
    }

    public function test_milk_per_day_fills_empty_days_and_matches_the_summary()
    {
        $cow = $this->cow();
        $this->milk($cow, 'am', 8);
        $this->milk($cow, 'pm', 6, 3);
        $this->milk($cow, 'am', 2, 3, discarded: true);

        $res = $this->chart(['metric' => 'milk.produced', 'group' => 'time', 'from' => $this->day(6), 'to' => $this->day(0)])
            ->assertOk()
            ->assertJsonPath('kind', 'time')
            ->assertJsonPath('unit', 'l')
            ->assertJsonCount(7, 'labels')
            ->assertJsonPath('labels.0', $this->day(6))
            ->assertJsonPath('labels.6', $this->day(0))
            ->assertJsonPath('series.0.key', 'kept')
            ->assertJsonPath('series.1.key', 'discarded');

        $this->assertEquals([0, 0, 0, 6, 0, 0, 8], $res->json('series.0.values'));
        $this->assertEquals([0, 0, 0, 2, 0, 0, 0], $res->json('series.1.values'));

        $summary = $this->getJson("/api/holdings/{$this->farm->id}/milk/summary?days=7")->json('totals');
        $this->assertEquals($summary['produced'], array_sum($res->json('series.0.values')) + array_sum($res->json('series.1.values')));
    }

    public function test_weeks_start_on_monday_and_keep_the_total()
    {
        $cow = $this->cow();
        foreach ([0, 5, 9, 13] as $ago) {
            $this->milk($cow, 'am', 5, $ago);
        }

        $from = $this->day(13);
        $res = $this->chart(['metric' => 'milk.produced', 'group' => 'time', 'from' => $from, 'to' => $this->day(), 'granularity' => 'week'])->assertOk();

        $this->assertSame(Carbon::parse($from)->startOfWeek(Carbon::MONDAY)->toDateString(), $res->json('labels.0'));
        $this->assertContains(count($res->json('labels')), [2, 3]);
        $this->assertEquals(20, array_sum($res->json('series.0.values')));
    }

    public function test_milk_by_session_lists_the_farms_milkings_in_order()
    {
        $cow = $this->cow();
        $this->milk($cow, 'pm', 4);
        $this->milk($cow, 'am', 7);

        $this->chart(['metric' => 'milk.produced', 'group' => 'session'])
            ->assertOk()
            ->assertJsonPath('kind', 'category')
            ->assertJsonPath('labels', ['Morning', 'Evening'])
            ->assertJsonPath('series.0.values', [7, 4]);
    }

    public function test_where_the_milk_went_counts_rejected_milk_once()
    {
        $buyer = $this->postJson("/api/holdings/{$this->farm->id}/milk/buyers", ['name' => 'Githunguri Dairy', 'kind' => 'coop', 'price_per_litre' => 50])
            ->assertCreated()->json('buyer.id');
        $url = "/api/holdings/{$this->farm->id}/milk/deliveries";
        $this->postJson($url, ['day' => $this->day(), 'kind' => 'coop', 'buyer_id' => $buyer, 'litres' => 15, 'rejected_litres' => 1])->assertCreated();
        $this->postJson($url, ['day' => $this->day(1), 'kind' => 'home', 'litres' => 3])->assertCreated();

        $res = $this->chart(['metric' => 'milk.outlets', 'group' => 'outlet'])->assertOk();
        $values = array_combine($res->json('labels'), $res->json('series.0.values'));
        $this->assertEquals(14, $values['Co-op / dairy']);
        $this->assertEquals(1, $values['Rejected at co-op']);
        $this->assertEquals(3, $values['Home use']);

        $this->chart(['metric' => 'milk.income', 'group' => 'buyer'])
            ->assertOk()
            ->assertJsonPath('labels', ['Githunguri Dairy'])
            ->assertJsonPath('series.0.values', [700]);   // (15 - 1) x 50
    }

    public function test_workers_see_milk_charts_but_not_money()
    {
        Sanctum::actingAs($this->addWorker());

        $this->chart(['metric' => 'milk.produced', 'group' => 'time'])->assertOk();
        $this->chart(['metric' => 'milk.income', 'group' => 'time'])->assertForbidden();
    }

    public function test_the_herd_by_breed_counts_active_animals_on_this_farm_only()
    {
        $this->cow(['breed_claimed' => 'Friesian']);
        $this->cow(['breed_claimed' => 'Friesian']);
        $this->cow(['breed_claimed' => 'Ayrshire']);
        $this->cow(['breed_claimed' => 'Jersey', 'status' => 'sold']);
        Animal::factory()->create(['breed_claimed' => 'Boran']);   // another farm

        $this->chart(['metric' => 'herd.composition', 'group' => 'breed'])
            ->assertOk()
            ->assertJsonPath('dated', false)
            ->assertJsonPath('labels', ['Friesian', 'Ayrshire'])
            ->assertJsonPath('series.0.values', [2, 1]);
    }

    public function test_health_records_are_counted_by_type()
    {
        $cow = $this->cow();
        foreach (['vaccination', 'vaccination', 'heat'] as $type) {
            $this->postJson("/api/animals/{$cow->id}/events", ['type' => $type, 'event_date' => $this->day(2)])->assertCreated();
        }

        $this->chart(['metric' => 'health.events', 'group' => 'type'])
            ->assertOk()
            ->assertJsonPath('labels', ['Vaccination', 'Heat'])
            ->assertJsonPath('series.0.values', [2, 1]);
    }

    public function test_bad_ranges_and_groups_are_refused()
    {
        $this->chart(['metric' => 'milk.produced', 'group' => 'time', 'to' => FarmClock::now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->chart(['metric' => 'milk.produced', 'group' => 'time', 'from' => $this->day(800)])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->chart(['metric' => 'milk.produced', 'group' => 'breed'])->assertStatus(422)->assertJsonValidationErrors('group');
        $this->chart(['metric' => 'milk.secret', 'group' => 'time'])->assertStatus(422);
    }

    public function test_another_farmers_reports_are_off_limits()
    {
        $other = Holding::factory()->create();

        $this->getJson("/api/holdings/{$other->id}/analytics?metric=milk.produced&group=time")->assertForbidden();
    }
}
