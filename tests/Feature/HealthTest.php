<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalEvent;
use App\Models\FarmMember;
use App\Models\Holding;
use App\Models\MilkLog;
use App\Models\User;
use App\Support\FarmClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Treatments with withdrawal periods: milk inside a withdrawal is always discarded, late and
// deleted treatments are applied to milk already logged, and the farm's health card.
class HealthTest extends TestCase
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

    /** n days ago in Kenya (negative = in the future). */
    private function day(int $daysAgo = 0): string
    {
        return FarmClock::now()->subDays($daysAgo)->toDateString();
    }

    private function treat(Animal $cow, string $date, array $fields = []): string
    {
        return $this->postJson("/api/animals/{$cow->id}/events", ['type' => 'treatment', 'event_date' => $date] + $fields)
            ->assertCreated()->json('event.id');
    }

    private function milk(Animal $cow, string $day, string $session, float $litres, bool $discarded = false)
    {
        return $this->postJson("/api/holdings/{$this->farm->id}/milk/session", [
            'day' => $day, 'session' => $session, 'entries' => [['animal_id' => $cow->id, 'litres' => $litres, 'discarded' => $discarded]],
        ])->assertOk();
    }

    private function log(Animal $cow, string $day, string $session): MilkLog
    {
        return MilkLog::where('animal_id', $cow->id)->where('milked_on', $day)->where('session', $session)->firstOrFail();
    }

    private function dailyDiscarded(Animal $cow, string $day): float
    {
        return (float) DB::table('milk_daily')->where('animal_id', $cow->id)->where('day', $day)->value('discarded');
    }

    public function test_milk_inside_a_withdrawal_is_saved_discarded_whatever_the_phone_sends()
    {
        $cow = $this->cow();
        $id = $this->treat($cow, $this->day(1), ['product' => 'Penstrep', 'milk_withdrawal_hours' => 72, 'meat_withdrawal_days' => 14]);

        // 72 h from yesterday = 3 days, plus a safety day: sellable again 3 days from now
        $this->assertSame($this->day(-3), AnimalEvent::find($id)->milk_clear_on->toDateString());
        $this->assertSame($this->day(-14), AnimalEvent::find($id)->meat_clear_on->toDateString());

        $this->milk($cow, $this->day(), 'am', 8, discarded: false)
            ->assertJsonPath('cows.0.withdrawal.until', $this->day(-3))
            ->assertJsonPath('cows.0.withdrawal.product', 'Penstrep');
        $log = $this->log($cow, $this->day(), 'am');
        $this->assertTrue($log->discarded);
        $this->assertSame($id, $log->withdrawal_event_id);
        $this->assertSame(8.0, $this->dailyDiscarded($cow, $this->day()));

        $this->getJson("/api/animals/{$cow->id}")
            ->assertJsonPath('withdrawal.milk_clear_on', $this->day(-3))
            ->assertJsonPath('withdrawal.meat_clear_on', $this->day(-14))
            ->assertJsonPath('withdrawal.product', 'Penstrep');
    }

    public function test_milk_is_sellable_again_on_the_clear_day()
    {
        $cow = $this->cow();
        $this->treat($cow, $this->day(10), ['milk_withdrawal_hours' => 24]);   // clears on day(8)

        $this->milk($cow, $this->day(9), 'am', 7);
        $this->milk($cow, $this->day(8), 'am', 7);

        $this->assertTrue($this->log($cow, $this->day(9), 'am')->discarded);
        $this->assertFalse($this->log($cow, $this->day(8), 'am')->discarded);
        $this->getJson("/api/animals/{$cow->id}")->assertJsonPath('withdrawal', null);
    }

    public function test_a_course_of_doses_counts_from_the_last_dose()
    {
        $cow = $this->cow();
        $id = $this->treat($cow, $this->day(10), ['course_days' => 3, 'milk_withdrawal_hours' => 24]);   // last dose day(8)

        $this->assertSame($this->day(6), AnimalEvent::find($id)->milk_clear_on->toDateString());
        $this->milk($cow, $this->day(7), 'pm', 5);
        $this->assertTrue($this->log($cow, $this->day(7), 'pm')->discarded);
    }

    public function test_a_late_treatment_locks_milk_already_logged_and_deleting_it_releases_only_that()
    {
        $cow = $this->cow();
        $this->milk($cow, $this->day(5), 'am', 8);
        $this->milk($cow, $this->day(5), 'pm', 6, discarded: true);   // discarded by hand (colostrum)
        $this->milk($cow, $this->day(2), 'am', 7);
        $this->assertSame(6.0, $this->dailyDiscarded($cow, $this->day(5)));

        $id = $this->treat($cow, $this->day(5), ['milk_withdrawal_hours' => 48]);   // clears on day(2)

        $this->assertTrue($this->log($cow, $this->day(5), 'am')->discarded);
        $this->assertSame($id, $this->log($cow, $this->day(5), 'am')->withdrawal_event_id);
        $this->assertNull($this->log($cow, $this->day(5), 'pm')->withdrawal_event_id);
        $this->assertFalse($this->log($cow, $this->day(2), 'am')->discarded);
        $this->assertSame(14.0, $this->dailyDiscarded($cow, $this->day(5)));

        $this->deleteJson("/api/animals/{$cow->id}/events/{$id}")->assertOk();

        $this->assertFalse($this->log($cow, $this->day(5), 'am')->discarded);
        $this->assertTrue($this->log($cow, $this->day(5), 'pm')->discarded);   // the hand-ticked one stays
        $this->assertSame(6.0, $this->dailyDiscarded($cow, $this->day(5)));
    }

    public function test_overlapping_treatments_keep_milk_locked_until_both_are_gone()
    {
        $cow = $this->cow();
        $this->milk($cow, $this->day(4), 'am', 9);
        $short = $this->treat($cow, $this->day(6), ['milk_withdrawal_hours' => 48]);   // clears day(3)
        $long = $this->treat($cow, $this->day(5), ['milk_withdrawal_hours' => 96]);    // clears today
        $this->assertSame($long, $this->log($cow, $this->day(4), 'am')->withdrawal_event_id);

        $this->deleteJson("/api/animals/{$cow->id}/events/{$long}")->assertOk();
        $log = $this->log($cow, $this->day(4), 'am');
        $this->assertTrue($log->discarded);
        $this->assertSame($short, $log->withdrawal_event_id);

        $this->deleteJson("/api/animals/{$cow->id}/events/{$short}")->assertOk();
        $this->assertFalse($this->log($cow, $this->day(4), 'am')->discarded);
    }

    public function test_health_fields_are_dropped_from_other_kinds_of_record()
    {
        $cow = $this->cow();
        $this->postJson("/api/animals/{$cow->id}/events", [
            'type' => 'calving', 'event_date' => $this->day(3), 'product' => 'Penstrep', 'milk_withdrawal_hours' => 72,
        ])->assertCreated()
            ->assertJsonPath('event.product', null)
            ->assertJsonPath('event.milk_clear_on', null);
    }

    public function test_health_fields_are_validated()
    {
        $cow = $this->cow();
        $url = "/api/animals/{$cow->id}/events";

        $this->postJson($url, ['type' => 'treatment', 'event_date' => $this->day(), 'milk_withdrawal_hours' => 3000])
            ->assertStatus(422)->assertJsonValidationErrors('milk_withdrawal_hours');
        $this->postJson($url, ['type' => 'treatment', 'event_date' => $this->day(), 'course_days' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('course_days');
        $this->postJson($url, ['type' => 'vaccination', 'event_date' => $this->day(), 'next_due_on' => $this->day(1)])
            ->assertStatus(422)->assertJsonValidationErrors('next_due_on');
    }

    public function test_the_farm_health_card_lists_withdrawals_and_doses_due()
    {
        $sick = $this->cow(['name' => 'Sick']);
        $this->treat($sick, $this->day(1), ['product' => 'Penstrep', 'milk_withdrawal_hours' => 72, 'meat_withdrawal_days' => 14]);

        $dueSoon = $this->cow(['name' => 'Due']);
        $this->postJson("/api/animals/{$dueSoon->id}/events", [
            'type' => 'deworming', 'event_date' => $this->day(80), 'product' => 'Albendazole', 'next_due_on' => $this->day(-10),
        ])->assertCreated();

        // Overdue on paper, but a later dose of the same vaccine was given: not listed
        $done = $this->cow(['name' => 'Done']);
        $this->postJson("/api/animals/{$done->id}/events", [
            'type' => 'vaccination', 'event_date' => $this->day(200), 'product' => 'FMD', 'next_due_on' => $this->day(20),
        ])->assertCreated();
        $this->postJson("/api/animals/{$done->id}/events", ['type' => 'vaccination', 'event_date' => $this->day(10), 'product' => 'FMD'])
            ->assertCreated();

        // Overdue and not done
        $late = $this->cow(['name' => 'Late']);
        $this->postJson("/api/animals/{$late->id}/events", [
            'type' => 'vaccination', 'event_date' => $this->day(200), 'product' => 'Lumpy skin', 'next_due_on' => $this->day(5),
        ])->assertCreated();

        $this->getJson("/api/holdings/{$this->farm->id}/health")
            ->assertOk()
            ->assertJsonCount(1, 'withdrawals')
            ->assertJsonPath('withdrawals.0.animal.name', 'Sick')
            ->assertJsonPath('withdrawals.0.milk_clear_on', $this->day(-3))
            ->assertJsonPath('withdrawals.0.meat_clear_on', $this->day(-14))
            ->assertJsonCount(2, 'due')
            ->assertJsonPath('due.0.animal.name', 'Late')
            ->assertJsonPath('due.0.overdue', true)
            ->assertJsonPath('due.1.animal.name', 'Due')
            ->assertJsonPath('due.1.overdue', false);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/holdings/{$this->farm->id}/health")->assertForbidden();
    }

    public function test_a_worker_records_a_treatment_and_the_milk_sheet_locks()
    {
        $cow = $this->cow();
        $worker = User::factory()->worker()->create();
        $member = new FarmMember(['role' => FarmMember::WORKER]);
        $member->forceFill(['holding_id' => $this->farm->id, 'user_id' => $worker->id])->save();
        Sanctum::actingAs($worker);

        $this->treat($cow, $this->day(), ['product' => 'Oxytetracycline', 'milk_withdrawal_hours' => 96]);

        $this->getJson("/api/holdings/{$this->farm->id}/milk/session?session=am")
            ->assertOk()
            ->assertJsonPath('cows.0.withdrawal.product', 'Oxytetracycline');
    }
}
