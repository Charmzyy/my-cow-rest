<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\FarmMember;
use App\Models\Holding;
use App\Models\MilkClash;
use App\Models\MilkDelivery;
use App\Models\MilkLog;
use App\Models\User;
use App\Support\FarmClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Phones that recorded with no signal send their entries late, sometimes twice, in any order.
// Resends never duplicate; for a cow's milking the entry typed last wins; when two different
// people disagreed, the losing entry is kept for the owner to check.
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $worker;
    private Holding $farm;
    private Animal $cow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->farm = Holding::factory()->for($this->owner, 'owner')->create(['milkings_per_day' => 2]);
        $this->cow = Animal::factory()->for($this->farm)->create(['name' => 'Daisy']);

        $this->worker = User::factory()->worker()->create();
        $member = new FarmMember(['role' => FarmMember::WORKER]);
        $member->forceFill(['holding_id' => $this->farm->id, 'user_id' => $this->worker->id])->save();
    }

    private function milk(User $as, ?float $litres, string $typedAt)
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/holdings/{$this->farm->id}/milk/session", [
            'day' => FarmClock::today(), 'session' => 'am',
            'entries' => [['animal_id' => $this->cow->id, 'litres' => $litres, 'entered_at' => $typedAt]],
        ])->assertOk();
    }

    private function at(int $minutesAgo): string
    {
        return Carbon::now()->subMinutes($minutesAgo)->toIso8601String();
    }

    public function test_the_entry_typed_last_wins_whatever_order_phones_sync_in()
    {
        $this->milk($this->owner, 9, $this->at(5));                       // owner, online, 5 min ago
        $this->milk($this->worker, 7, $this->at(30))->assertJsonPath('stale', 1);   // worker's phone syncs late: typed earlier

        $this->assertEquals(9, MilkLog::sole()->litres);

        // The worker's lost entry is kept for the owner, with who typed what
        $clash = MilkClash::sole();
        $this->assertEquals(9, $clash->kept_litres);
        $this->assertEquals(7, $clash->lost_litres);
        $this->assertSame($this->worker->id, $clash->lost_by);
    }

    public function test_a_newer_offline_entry_replaces_an_older_one_and_is_flagged()
    {
        $this->milk($this->owner, 9, $this->at(30));
        $this->milk($this->worker, 7, $this->at(5))->assertJsonPath('stale', 0);

        $this->assertEquals(7, MilkLog::sole()->litres);
        $this->assertEquals(7, MilkClash::sole()->kept_litres);
        $this->assertEquals(9, MilkClash::sole()->lost_litres);
    }

    public function test_correcting_your_own_entry_or_agreeing_is_not_a_clash()
    {
        $this->milk($this->worker, 7, $this->at(30));
        $this->milk($this->worker, 7.5, $this->at(20));   // same person fixes a typo
        $this->milk($this->owner, 7.5, $this->at(10));    // someone else, same number

        $this->assertSame(0, MilkClash::count());
        $this->assertEquals(7.5, MilkLog::sole()->litres);
    }

    public function test_a_phone_clock_in_the_future_cannot_lock_an_entry()
    {
        $this->milk($this->worker, 7, Carbon::now()->addDays(3)->toIso8601String());
        $this->milk($this->owner, 9, $this->at(0))->assertJsonPath('stale', 0);

        $this->assertEquals(9, MilkLog::sole()->litres);
    }

    public function test_owners_review_clashes_and_workers_cannot()
    {
        $this->milk($this->owner, 9, $this->at(5));
        $this->milk($this->worker, 7, $this->at(30));

        Sanctum::actingAs($this->worker);
        $this->getJson("/api/holdings/{$this->farm->id}/milk/clashes")->assertForbidden();

        Sanctum::actingAs($this->owner);
        $id = $this->getJson("/api/holdings/{$this->farm->id}/milk/clashes")
            ->assertOk()
            ->assertJsonPath('clashes.0.animal.name', 'Daisy')
            ->assertJsonPath('clashes.0.lost_by_user.fullname', $this->worker->fullname)
            ->json('clashes.0.id');

        $this->putJson("/api/holdings/{$this->farm->id}/milk/clashes/seen", ['ids' => [$id]])->assertOk()->assertJsonPath('marked', 1);
        $this->getJson("/api/holdings/{$this->farm->id}/milk/clashes")->assertJsonCount(0, 'clashes');
    }

    public function test_a_resent_delivery_is_saved_once()
    {
        Sanctum::actingAs($this->worker);
        $id = '9a7c1f0e-0000-4000-8000-0000000000d1';
        $body = ['id' => $id, 'day' => FarmClock::today(), 'kind' => 'home', 'litres' => 3];
        $url = "/api/holdings/{$this->farm->id}/milk/deliveries";

        $this->postJson($url, $body)->assertCreated()->assertJsonPath('delivery.id', $id);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('delivery.id', $id);

        $this->assertSame(1, MilkDelivery::count());
    }

    public function test_a_resent_health_record_is_saved_once()
    {
        Sanctum::actingAs($this->worker);
        $id = '9a7c1f0e-0000-4000-8000-0000000000e1';
        $body = ['id' => $id, 'type' => 'vaccination', 'event_date' => FarmClock::today(), 'product' => 'FMD vaccine'];

        $this->postJson("/api/animals/{$this->cow->id}/events", $body)->assertCreated();
        $this->postJson("/api/animals/{$this->cow->id}/events", $body)->assertOk()->assertJsonCount(1, 'events');

        // The same id can't be reused for another cow
        $other = Animal::factory()->for($this->farm)->create();
        $this->postJson("/api/animals/{$other->id}/events", $body)->assertStatus(409);
    }
}
