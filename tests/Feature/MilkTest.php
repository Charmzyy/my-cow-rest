<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\FarmMember;
use App\Models\Holding;
use App\Models\MilkLog;
use App\Models\User;
use App\Support\FarmClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Milking sessions, the daily rollup, lactations, deliveries, and who may do what.
class MilkTest extends TestCase
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

    private function cow(array $attrs = []): Animal
    {
        return Animal::factory()->for($this->farm)->create($attrs);
    }

    private function day(int $daysAgo = 0): string
    {
        return FarmClock::now()->subDays($daysAgo)->toDateString();
    }

    private function save(string $session, array $entries, ?string $day = null)
    {
        return $this->postJson("/api/holdings/{$this->farm->id}/milk/session", [
            'day' => $day ?? $this->day(), 'session' => $session, 'entries' => $entries,
        ]);
    }

    private function event(Animal $cow, string $type, string $date)
    {
        return $this->postJson("/api/animals/{$cow->id}/events", ['type' => $type, 'event_date' => $date])->assertCreated();
    }

    // ---- The session sheet ---------------------------------------------------------------------

    public function test_the_sheet_lists_the_farms_females_with_cows_in_milk_first()
    {
        $dry = $this->cow(['name' => 'Aisha']);
        $milking = $this->cow(['name' => 'Zawadi']);
        $this->cow(['name' => 'Bull', 'sex' => 'male']);
        Animal::factory()->create();   // another farm's cow
        $this->event($milking, 'calving', $this->day(40));
        $this->event($dry, 'calving', $this->day(400));
        $this->event($dry, 'dry_off', $this->day(60));

        $this->getJson("/api/holdings/{$this->farm->id}/milk/session?session=am")
            ->assertOk()
            ->assertJsonPath('sessions', ['am', 'pm'])
            ->assertJsonCount(2, 'cows')
            ->assertJsonPath('cows.0.name', 'Zawadi')
            ->assertJsonPath('cows.0.status', 'milking')
            ->assertJsonPath('cows.0.days_in_milk', 40)
            ->assertJsonPath('cows.1.status', 'dry');
    }

    public function test_saving_a_session_twice_updates_instead_of_duplicating()
    {
        $a = $this->cow();
        $b = $this->cow();

        $this->save('am', [['animal_id' => $a->id, 'litres' => 8.5], ['animal_id' => $b->id, 'litres' => 6]])
            ->assertOk()->assertJsonPath('saved', 2)->assertJsonPath('total', 14.5);
        $this->save('am', [['animal_id' => $a->id, 'litres' => 9], ['animal_id' => $b->id, 'litres' => 6]])
            ->assertOk()->assertJsonPath('total', 15.0);

        $this->assertSame(2, MilkLog::count());
        $this->assertEquals(9, DB::table('milk_daily')->where('animal_id', $a->id)->value('litres'));
    }

    public function test_the_daily_rollup_adds_sessions_and_tracks_discarded_milk()
    {
        $cow = $this->cow();
        $this->save('am', [['animal_id' => $cow->id, 'litres' => 7]]);
        $this->save('pm', [['animal_id' => $cow->id, 'litres' => 5, 'discarded' => true]]);

        $row = DB::table('milk_daily')->where('animal_id', $cow->id)->first();
        $this->assertEquals(12, $row->litres);
        $this->assertEquals(5, $row->discarded);
        $this->assertSame(2, (int) $row->sessions);

        // Clearing an entry removes it from the day
        $this->save('pm', [['animal_id' => $cow->id, 'litres' => null]])->assertJsonPath('cleared', 1);
        $this->assertEquals(7, DB::table('milk_daily')->where('animal_id', $cow->id)->value('litres'));
    }

    public function test_the_phones_id_is_kept_so_a_resent_batch_matches()
    {
        $cow = $this->cow();
        $id = '9a7c1f0e-0000-4000-8000-000000000001';

        $this->save('am', [['id' => $id, 'animal_id' => $cow->id, 'litres' => 4]]);
        $this->save('am', [['id' => $id, 'animal_id' => $cow->id, 'litres' => 4]]);

        $this->assertSame([$id], MilkLog::pluck('id')->all());
    }

    public function test_bad_sessions_are_refused()
    {
        $cow = $this->cow();
        $other = Animal::factory()->create();

        $this->save('midday', [['animal_id' => $cow->id, 'litres' => 3]])->assertStatus(422);              // 2-milking farm
        $this->save('am', [['animal_id' => $cow->id, 'litres' => 3]], $this->day(-1))->assertStatus(422);  // tomorrow
        $this->save('am', [['animal_id' => $cow->id, 'litres' => 3]], $this->day(120))->assertStatus(422); // too old
        $this->save('am', [['animal_id' => $cow->id, 'litres' => 75]])->assertStatus(422);                 // not a cow
        $this->save('am', [['animal_id' => $other->id, 'litres' => 3]])->assertStatus(422);                // not this farm
    }

    public function test_a_three_milking_farm_records_midday()
    {
        $this->farm->update(['milkings_per_day' => 3]);
        $cow = $this->cow();

        $this->save('midday', [['animal_id' => $cow->id, 'litres' => 3]])->assertOk();
    }

    // ---- Who may record ------------------------------------------------------------------------

    public function test_workers_log_milk_and_outsiders_cannot()
    {
        $cow = $this->cow();
        $worker = User::factory()->worker()->create();
        $member = new FarmMember(['role' => FarmMember::WORKER]);
        $member->forceFill(['holding_id' => $this->farm->id, 'user_id' => $worker->id])->save();

        Sanctum::actingAs($worker);
        $this->save('am', [['animal_id' => $cow->id, 'litres' => 6]])->assertOk();
        $this->getJson("/api/holdings/{$this->farm->id}/milk/summary")->assertOk()->assertJsonPath('shows_money', false);

        Sanctum::actingAs(User::factory()->create());
        $this->save('am', [['animal_id' => $cow->id, 'litres' => 6]])->assertForbidden();
        $this->getJson("/api/holdings/{$this->farm->id}/milk/session")->assertForbidden();
        $this->getJson("/api/animals/{$cow->id}/milk")->assertForbidden();
    }

    // ---- Lactations ----------------------------------------------------------------------------

    public function test_lactations_follow_calving_and_dry_off_records()
    {
        $cow = $this->cow();
        $this->event($cow, 'calving', $this->day(500));
        $dryOff = $this->event($cow, 'dry_off', $this->day(200))->json('event.id');
        $this->event($cow, 'calving', $this->day(100));

        $this->getJson("/api/animals/{$cow->id}/milk")
            ->assertJsonPath('status', 'milking')
            ->assertJsonCount(2, 'lactations')
            ->assertJsonPath('lactations.0.number', 2)          // newest first
            ->assertJsonPath('lactations.0.dried_on', null)
            ->assertJsonPath('lactations.1.dried_on', $this->day(200));

        // Removing the dry-off record re-opens the first lactation until the next calving
        $this->deleteJson("/api/animals/{$cow->id}/events/{$dryOff}")->assertOk();
        $this->assertNull($cow->lactations()->where('number', 1)->value('dried_on'));
    }

    public function test_the_cow_chart_has_one_point_per_day()
    {
        $cow = $this->cow();
        $this->save('am', [['animal_id' => $cow->id, 'litres' => 7]], $this->day(2));

        $series = $this->getJson("/api/animals/{$cow->id}/milk?days=7")->assertOk()->json('series');

        $this->assertCount(7, $series);
        $this->assertSame($this->day(), end($series)['day']);
        $this->assertEquals(7, $series[4]['litres']);
        $this->assertEquals(0, $series[5]['litres']);
    }

    // ---- Where the milk went -------------------------------------------------------------------

    public function test_deliveries_use_the_buyers_price_and_the_summary_balances()
    {
        $cow = $this->cow();
        $this->save('am', [['animal_id' => $cow->id, 'litres' => 20]]);
        $this->save('pm', [['animal_id' => $cow->id, 'litres' => 2, 'discarded' => true]]);

        $coop = $this->postJson("/api/holdings/{$this->farm->id}/milk/buyers", ['name' => 'Githunguri Dairy', 'kind' => 'coop', 'price_per_litre' => 50])
            ->assertCreated()->json('buyer.id');
        $url = "/api/holdings/{$this->farm->id}/milk/deliveries";
        $this->postJson($url, ['day' => $this->day(), 'kind' => 'coop', 'buyer_id' => $coop, 'litres' => 15, 'rejected_litres' => 1])
            ->assertCreated()
            ->assertJsonPath('delivery.price_per_litre', 50.0)
            ->assertJsonPath('delivery.amount', 700.0);   // (15 - 1) x 50
        $this->postJson($url, ['day' => $this->day(), 'kind' => 'sale', 'litres' => 2, 'price_per_litre' => 60, 'paid' => true])->assertCreated();
        $this->postJson($url, ['day' => $this->day(), 'kind' => 'home', 'litres' => 3, 'price_per_litre' => 99])
            ->assertJsonPath('delivery.price_per_litre', null);   // home milk is never priced

        $this->getJson("/api/holdings/{$this->farm->id}/milk/summary?days=7")
            ->assertOk()
            ->assertJsonPath('totals.produced', 22.0)
            ->assertJsonPath('totals.discarded', 2.0)
            ->assertJsonPath('totals.coop', 15.0)
            ->assertJsonPath('totals.rejected', 1.0)
            ->assertJsonPath('totals.home', 3.0)
            ->assertJsonPath('totals.income', 820.0)
            ->assertJsonPath('totals.unpaid', 700.0)
            ->assertJsonPath('cows.0.litres', 22.0);
    }

    public function test_rejected_milk_cannot_exceed_the_delivery()
    {
        $this->postJson("/api/holdings/{$this->farm->id}/milk/deliveries", ['day' => $this->day(), 'kind' => 'coop', 'litres' => 5, 'rejected_litres' => 6])
            ->assertStatus(422)->assertJsonValidationErrors('rejected_litres');
    }

    public function test_a_worker_can_only_change_their_own_delivery_entries()
    {
        $worker = User::factory()->worker()->create();
        $member = new FarmMember(['role' => FarmMember::WORKER]);
        $member->forceFill(['holding_id' => $this->farm->id, 'user_id' => $worker->id])->save();
        $url = "/api/holdings/{$this->farm->id}/milk/deliveries";
        $ownersEntry = $this->postJson($url, ['day' => $this->day(), 'kind' => 'calves', 'litres' => 4])->json('delivery.id');

        Sanctum::actingAs($worker);
        $mine = $this->postJson($url, ['day' => $this->day(), 'kind' => 'coop', 'litres' => 10])->assertCreated()->json('delivery.id');
        $this->deleteJson("{$url}/{$ownersEntry}")->assertForbidden();
        $this->deleteJson("{$url}/{$mine}")->assertOk();
        $this->postJson("/api/holdings/{$this->farm->id}/milk/buyers", ['name' => 'X', 'kind' => 'local'])->assertForbidden();
    }

    // ---- Ear-tag QR codes ----------------------------------------------------------------------

    public function test_every_animal_gets_a_qr_code_that_finds_it_again()
    {
        $cow = $this->cow();
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $cow->qr_code);

        $typed = strtolower(substr($cow->qr_code, 0, 4) . '-' . substr($cow->qr_code, 4));
        $this->getJson("/api/tags/{$typed}")->assertOk()->assertJsonPath('animal.id', $cow->id);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/tags/{$cow->qr_code}")->assertForbidden();
        $this->getJson('/api/tags/NOTACODE')->assertNotFound();
    }

    public function test_the_tag_sheet_is_a_pdf()
    {
        $this->cow();

        $this->get("/api/holdings/{$this->farm->id}/tags.pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
