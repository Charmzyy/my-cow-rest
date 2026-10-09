<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\FarmMember;
use App\Models\Holding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Owners, managers and workers on a farm: who can do what (FarmMember::ABILITIES).
class FarmTeamTest extends TestCase
{
    use RefreshDatabase;

    private function farmOf(User $owner): Holding
    {
        return Holding::factory()->for($owner, 'owner')->create();
    }

    private function join(Holding $farm, User $user, string $role): FarmMember
    {
        $member = new FarmMember(['role' => $role]);
        $member->forceFill(['holding_id' => $farm->id, 'user_id' => $user->id])->save();

        return $member;
    }

    /** Sign in through the real PIN endpoint and use the token it returns. */
    private function pinLogin(string $phone, string $pin)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/login/pin', ['phone' => $phone, 'pin' => $pin]);
    }

    private function withTokenOf($response): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($response->json('token'));
    }

    // ---- Owner -------------------------------------------------------------------------------

    public function test_whoever_creates_a_farm_is_its_owner()
    {
        $this->actingAsRole();

        $this->postJson('/api/holdings', ['name' => 'Kimani Farm', 'county_id' => 22])
            ->assertCreated()
            ->assertJsonPath('holding.my_role', 'owner');
        $this->getJson('/api/holdings')->assertJsonPath('holdings.0.my_role', 'owner');
    }

    public function test_the_owner_cannot_be_removed()
    {
        $owner = $this->actingAsRole();
        $farm = $this->farmOf($owner);
        $ownerMember = $farm->members()->where('user_id', $owner->id)->first();

        $this->deleteJson("/api/holdings/{$farm->id}/members/{$ownerMember->id}")->assertStatus(422);
    }

    // ---- Workers -----------------------------------------------------------------------------

    public function test_an_owner_adds_a_worker_who_signs_in_with_phone_and_pin()
    {
        $owner = $this->actingAsRole();
        $farm = $this->farmOf($owner);

        $this->postJson("/api/holdings/{$farm->id}/workers", ['fullname' => 'Otieno', 'phone' => '0712 345 678', 'pin' => '2580'])
            ->assertCreated()
            ->assertJsonPath('member.role', 'worker')
            ->assertJsonPath('member.user.login_phone', '+254712345678');

        $this->pinLogin('+254 712 345678', '2580')
            ->assertCreated()
            ->assertJsonPath('role.0', 'worker')
            ->assertJsonStructure(['token']);
        $this->pinLogin('0712345678', '9999')->assertUnauthorized();
    }

    public function test_weak_pins_and_non_kenyan_numbers_are_refused()
    {
        $farm = $this->farmOf($this->actingAsRole());

        $this->postJson("/api/holdings/{$farm->id}/workers", ['fullname' => 'A', 'phone' => '0712345678', 'pin' => '1234'])
            ->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->postJson("/api/holdings/{$farm->id}/workers", ['fullname' => 'A', 'phone' => '020 123456', 'pin' => '2580'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_a_worker_sees_the_farms_herd_and_logs_records_but_cannot_change_animals()
    {
        $owner = User::factory()->create();
        $farm = $this->farmOf($owner);
        $cow = Animal::factory()->for($farm)->create();
        $otherCow = Animal::factory()->create();   // another farm
        $worker = User::factory()->worker()->create();
        $this->join($farm, $worker, FarmMember::WORKER);
        Sanctum::actingAs($worker);

        $this->getJson('/api/holdings')->assertJsonPath('holdings.0.my_role', 'worker');
        $this->getJson('/api/animals')->assertOk()->assertJsonCount(1, 'animals')->assertJsonPath('animals.0.id', $cow->id);
        $this->getJson("/api/animals/{$cow->id}")->assertOk()->assertJsonPath('can', ['log_event']);
        $this->postJson("/api/animals/{$cow->id}/events", ['type' => 'deworming', 'event_date' => now()->toDateString()])
            ->assertCreated();

        // Not theirs to change
        $this->putJson("/api/animals/{$cow->id}", ['tag_number' => 'X-1', 'sex' => 'female', 'county_id' => 22])->assertForbidden();
        $this->deleteJson("/api/animals/{$cow->id}")->assertForbidden();
        $this->postJson('/api/animals', ['tag_number' => 'KE-555', 'sex' => 'female', 'holding_id' => $farm->id])->assertForbidden();
        $this->postJson("/api/animals/{$cow->id}/certification-requests", ['inspection_method' => 'photo'])->assertForbidden();
        $this->getJson("/api/holdings/{$farm->id}/members")->assertForbidden();
        // Nor another farm's animals
        $this->getJson("/api/animals/{$otherCow->id}")->assertForbidden();
    }

    public function test_a_worker_can_remove_only_the_records_they_entered()
    {
        $owner = User::factory()->create();
        $farm = $this->farmOf($owner);
        $cow = Animal::factory()->for($farm)->create();
        $worker = User::factory()->worker()->create();
        $this->join($farm, $worker, FarmMember::WORKER);

        Sanctum::actingAs($owner);
        $ownersRecord = $this->postJson("/api/animals/{$cow->id}/events", ['type' => 'vaccination', 'event_date' => now()->toDateString()])->json('event.id');
        Sanctum::actingAs($worker);
        $workersRecord = $this->postJson("/api/animals/{$cow->id}/events", ['type' => 'deworming', 'event_date' => now()->toDateString()])->json('event.id');

        $this->deleteJson("/api/animals/{$cow->id}/events/{$ownersRecord}")->assertForbidden();
        $this->deleteJson("/api/animals/{$cow->id}/events/{$workersRecord}")->assertOk();
    }

    public function test_one_worker_can_work_on_two_farms_with_one_pin()
    {
        $farmA = $this->farmOf(User::factory()->create());
        $farmB = $this->farmOf($ownerB = User::factory()->create());
        $worker = User::factory()->worker('+254700111222')->create();
        $this->join($farmA, $worker, FarmMember::WORKER);

        Sanctum::actingAs($ownerB);
        $this->postJson("/api/holdings/{$farmB->id}/workers", ['fullname' => 'Same person', 'phone' => '0700111222', 'pin' => '7391'])
            ->assertCreated()
            ->assertJsonPath('existing_account', true);

        $this->assertSame(1, User::where('login_phone', '+254700111222')->count());
        $this->pinLogin('0700111222', '2580')->assertCreated();   // original PIN unchanged
    }

    public function test_resetting_a_pin_signs_the_worker_out()
    {
        $owner = User::factory()->create();
        $farm = $this->farmOf($owner);
        $worker = User::factory()->worker()->create();
        $member = $this->join($farm, $worker, FarmMember::WORKER);
        $session = $this->pinLogin('0712000001', '2580')->assertCreated();

        Sanctum::actingAs($owner);
        $this->putJson("/api/holdings/{$farm->id}/members/{$member->id}/pin", ['pin' => '6047'])->assertOk();

        $this->withTokenOf($session)->getJson('/api/animals')->assertUnauthorized();
        $this->pinLogin('0712000001', '2580')->assertUnauthorized();
        $this->pinLogin('0712000001', '6047')->assertCreated();
    }

    public function test_a_removed_worker_loses_access_immediately()
    {
        $owner = User::factory()->create();
        $farm = $this->farmOf($owner);
        $worker = User::factory()->worker()->create();
        $member = $this->join($farm, $worker, FarmMember::WORKER);
        $session = $this->pinLogin('0712000001', '2580')->assertCreated();

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/holdings/{$farm->id}/members/{$member->id}")->assertOk();

        $this->withTokenOf($session)->getJson('/api/animals')->assertUnauthorized();
        $this->pinLogin('0712000001', '2580')->assertForbidden();
    }

    // ---- Managers ----------------------------------------------------------------------------

    public function test_a_manager_runs_the_herd_for_the_owner()
    {
        $owner = User::factory()->create();
        $farm = $this->farmOf($owner);
        $manager = User::factory()->create(['email' => 'manager@example.com']);

        Sanctum::actingAs($owner);
        $this->postJson("/api/holdings/{$farm->id}/managers", ['email' => 'manager@example.com'])->assertCreated();

        Sanctum::actingAs($manager);
        $id = $this->postJson('/api/animals', ['tag_number' => 'KE-777001', 'sex' => 'female', 'holding_id' => $farm->id])
            ->assertCreated()
            ->assertJsonPath('animal.owner_id', $owner->id)   // the farm owner's animal, not the manager's
            ->json('animal.id');
        $this->getJson("/api/animals/{$id}")->assertJsonPath('can', ['update', 'delete', 'log_event']);
        $this->putJson("/api/holdings/{$farm->id}", ['name' => 'Renamed', 'county_id' => 22])->assertOk();
        $this->postJson("/api/holdings/{$farm->id}/workers", ['fullname' => 'Hand', 'phone' => '0799000111', 'pin' => '2580'])->assertCreated();

        // Owner-only
        $this->postJson("/api/animals/{$id}/certification-requests", ['inspection_method' => 'photo'])->assertForbidden();
        $this->postJson("/api/holdings/{$farm->id}/managers", ['email' => $owner->email])->assertForbidden();
        $this->deleteJson("/api/holdings/{$farm->id}")->assertForbidden();
    }

    public function test_a_manager_cannot_remove_another_manager()
    {
        $owner = User::factory()->create();
        $farm = $this->farmOf($owner);
        $this->join($farm, $manager = User::factory()->create(), FarmMember::MANAGER);
        $other = $this->join($farm, User::factory()->create(), FarmMember::MANAGER);

        Sanctum::actingAs($manager);
        $this->deleteJson("/api/holdings/{$farm->id}/members/{$other->id}")->assertForbidden();
    }

    public function test_an_animal_cannot_be_moved_to_a_farm_with_another_owner()
    {
        $manager = $this->actingAsRole();
        $farmA = $this->farmOf(User::factory()->create());
        $farmB = $this->farmOf(User::factory()->create());
        $this->join($farmA, $manager, FarmMember::MANAGER);
        $this->join($farmB, $manager, FarmMember::MANAGER);
        $cow = Animal::factory()->for($farmA)->create();

        $this->putJson("/api/animals/{$cow->id}", ['tag_number' => $cow->tag_number, 'sex' => 'female', 'holding_id' => $farmB->id])
            ->assertStatus(422);
    }

    public function test_only_full_farmer_accounts_can_be_managers()
    {
        $farm = $this->farmOf($this->actingAsRole());
        User::factory()->officer()->create(['email' => 'vet@example.com']);

        $this->postJson("/api/holdings/{$farm->id}/managers", ['email' => 'vet@example.com'])->assertStatus(422);
        $this->postJson("/api/holdings/{$farm->id}/managers", ['email' => 'nobody@example.com'])->assertStatus(422);
    }
}
