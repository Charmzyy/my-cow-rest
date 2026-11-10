<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\FarmMember;
use App\Models\Holding;
use App\Models\Organisation;
use App\Models\OrganisationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Organisations (workspaces): every farm sits in one, and the X-Organisation header keeps
// a request inside it. Farm access itself still comes from the farm's team.
class OrganisationTest extends TestCase
{
    use RefreshDatabase;

    private function farmOf(User $owner): Holding
    {
        return Holding::factory()->for($owner, 'owner')->create();
    }

    private function coopOf(User $owner): Organisation
    {
        return Organisation::findOrFail(
            $this->postJson('/api/orgs', ['name' => 'Githunguri Dairy Co-op', 'type' => 'coop'])->assertCreated()->json('organisation.id')
        );
    }

    private function join(Holding $farm, User $user, string $role): FarmMember
    {
        $member = new FarmMember(['role' => $role]);
        $member->forceFill(['holding_id' => $farm->id, 'user_id' => $user->id])->save();

        return $member;
    }

    public function test_a_first_farm_creates_the_farmers_personal_organisation()
    {
        $this->actingAsRole();

        $farmOrg = $this->postJson('/api/holdings', ['name' => 'Kimani Farm', 'county_id' => 22])
            ->assertCreated()->json('holding.organisation_id');

        $this->getJson('/api/me')
            ->assertJsonCount(1, 'organisations')
            ->assertJsonPath('organisations.0.id', $farmOrg)
            ->assertJsonPath('organisations.0.type', 'personal')
            ->assertJsonPath('organisations.0.my_role', 'owner');
    }

    public function test_the_header_keeps_a_request_inside_one_workspace()
    {
        $farmer = $this->actingAsRole();
        $own = $this->farmOf($farmer);
        $coop = $this->coopOf($farmer);

        $coopFarm = $this->withHeader('X-Organisation', $coop->id)
            ->postJson('/api/holdings', ['name' => 'Co-op Farm', 'county_id' => 22])
            ->assertCreated()->json('holding.id');

        $this->withHeader('X-Organisation', $own->organisation_id)->getJson('/api/holdings')
            ->assertJsonCount(1, 'holdings')->assertJsonPath('holdings.0.id', $own->id);
        $this->withHeader('X-Organisation', $coop->id)->getJson('/api/holdings')
            ->assertJsonCount(1, 'holdings')->assertJsonPath('holdings.0.id', $coopFarm);

        // No header (older app builds): every farm I'm on
        $this->withHeaders(['X-Organisation' => ''])->getJson('/api/holdings')->assertJsonCount(2, 'holdings');
    }

    public function test_a_farm_or_animal_in_another_workspace_is_not_found()
    {
        $farmer = $this->actingAsRole();
        $own = $this->farmOf($farmer);
        $cow = Animal::factory()->for($own)->create();
        $coop = $this->coopOf($farmer);

        $this->withHeader('X-Organisation', $coop->id)->getJson("/api/holdings/{$own->id}/milk/summary")->assertNotFound();
        $this->withHeader('X-Organisation', $coop->id)->getJson("/api/animals/{$cow->id}")->assertNotFound();
        $this->withHeader('X-Organisation', $own->organisation_id)->getJson("/api/animals/{$cow->id}")->assertOk();
    }

    public function test_someone_elses_organisation_is_refused()
    {
        $stranger = $this->farmOf(User::factory()->create());
        $this->actingAsRole();

        $this->withHeader('X-Organisation', $stranger->organisation_id)->getJson('/api/holdings')->assertForbidden();
        $this->withHeader('X-Organisation', 'not-an-id')->getJson('/api/animals')->assertForbidden();
    }

    public function test_joining_a_farm_team_joins_its_organisation_and_leaving_the_last_farm_leaves_it()
    {
        $farm = $this->farmOf(User::factory()->create());
        $worker = User::factory()->create();

        $member = $this->join($farm, $worker, FarmMember::WORKER);
        $this->assertSame(OrganisationMember::MEMBER, $farm->organisation->roleOf($worker));

        $member->delete();
        $this->assertNull($farm->organisation->roleOf($worker));
    }

    public function test_only_the_people_who_run_a_workspace_add_farms_to_it()
    {
        $boss = $this->actingAsRole();
        $coop = $this->coopOf($boss);
        $farmer = User::factory()->create(['email' => 'wanjiru@example.com']);
        $this->postJson("/api/orgs/{$coop->id}/members", ['email' => 'wanjiru@example.com', 'role' => 'member'])->assertCreated();

        Sanctum::actingAs($farmer);
        $this->withHeader('X-Organisation', $coop->id)
            ->postJson('/api/holdings', ['name' => 'Wanjiru Farm', 'county_id' => 22])
            ->assertForbidden();
        $this->getJson("/api/orgs/{$coop->id}/members")->assertForbidden();
    }

    public function test_admins_manage_members_but_only_the_owner_manages_admins()
    {
        $boss = $this->actingAsRole();
        $coop = $this->coopOf($boss);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        User::factory()->create(['email' => 'other@example.com']);
        $this->postJson("/api/orgs/{$coop->id}/members", ['email' => 'admin@example.com', 'role' => 'admin'])->assertCreated();

        Sanctum::actingAs($admin);
        $this->postJson("/api/orgs/{$coop->id}/members", ['email' => 'other@example.com', 'role' => 'admin'])->assertForbidden();
        $this->postJson("/api/orgs/{$coop->id}/members", ['email' => 'other@example.com', 'role' => 'member'])->assertCreated();
        $ownerRow = $coop->members()->where('user_id', $boss->id)->first();
        $this->deleteJson("/api/orgs/{$coop->id}/members/{$ownerRow->id}")->assertStatus(422);
    }

    public function test_the_owner_moves_a_farm_with_its_animals_into_a_workspace_they_run()
    {
        $farmer = $this->actingAsRole();
        $farm = $this->farmOf($farmer);
        $cow = Animal::factory()->for($farm)->create();
        $worker = User::factory()->create();
        $this->join($farm, $worker, FarmMember::WORKER);
        $coop = $this->coopOf($farmer);

        $this->putJson("/api/holdings/{$farm->id}/organisation", ['organisation_id' => $coop->id])->assertOk();

        $this->assertSame($coop->id, $farm->fresh()->organisation_id);
        $this->assertSame($coop->id, $cow->fresh()->organisation_id);
        $this->assertSame(OrganisationMember::MEMBER, $coop->roleOf($worker));
    }

    public function test_a_farm_cannot_be_moved_into_someone_elses_workspace()
    {
        $other = User::factory()->create();
        $theirs = Organisation::personalFor($other);
        $farm = $this->farmOf($this->actingAsRole());

        $this->putJson("/api/holdings/{$farm->id}/organisation", ['organisation_id' => $theirs->id])->assertForbidden();
    }
}
