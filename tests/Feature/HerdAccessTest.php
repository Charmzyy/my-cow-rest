<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\CertificationRequest;
use App\Models\Holding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Farms and animals: owners manage their own; nobody else can read or change them.
class HerdAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_animal_registered_on_a_farm_takes_the_farms_location()
    {
        $farmer = $this->actingAsRole();
        $farm = Holding::factory()->for($farmer, 'owner')->create(['county_id' => 19, 'village' => 'Karatina']);

        $this->postJson('/api/animals', ['tag_number' => 'KE-000123', 'sex' => 'female', 'holding_id' => $farm->id])
            ->assertCreated()
            ->assertJsonPath('animal.county_id', 19)
            ->assertJsonPath('animal.village', 'Karatina')
            ->assertJsonPath('animal.species', 'cattle');
    }

    public function test_an_animal_cannot_be_put_on_someone_elses_farm()
    {
        $this->actingAsRole();
        $otherFarm = Holding::factory()->create();

        $this->postJson('/api/animals', ['tag_number' => 'KE-000124', 'sex' => 'female', 'holding_id' => $otherFarm->id])
            ->assertStatus(422);
    }

    public function test_tag_numbers_are_unique()
    {
        $farmer = $this->actingAsRole();
        $farm = Holding::factory()->for($farmer, 'owner')->create();
        Animal::factory()->create(['tag_number' => 'KE-000777']);

        $this->postJson('/api/animals', ['tag_number' => 'KE-000777', 'sex' => 'female', 'holding_id' => $farm->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tag_number');
    }

    public function test_the_herd_list_only_shows_the_farmers_own_animals()
    {
        $farmer = $this->actingAsRole();
        $mine = Animal::factory()->for(Holding::factory()->for($farmer, 'owner'))->create();
        Animal::factory()->create(); // someone else's

        $this->getJson('/api/animals')
            ->assertOk()
            ->assertJsonCount(1, 'animals')
            ->assertJsonPath('animals.0.id', $mine->id);
    }

    public function test_another_farmer_cannot_see_change_or_remove_my_animal()
    {
        $animal = Animal::factory()->create();
        $this->actingAsRole();

        $this->getJson("/api/animals/{$animal->id}")->assertForbidden();
        $this->putJson("/api/animals/{$animal->id}", ['tag_number' => 'KE-999999', 'sex' => 'female', 'county_id' => 22])->assertForbidden();
        $this->deleteJson("/api/animals/{$animal->id}")->assertForbidden();
        $this->postJson("/api/animals/{$animal->id}/events", ['type' => 'deworming', 'event_date' => now()->toDateString()])->assertForbidden();

        $this->assertNotSoftDeleted($animal);
    }

    public function test_an_animal_with_an_open_certification_request_cannot_be_removed()
    {
        $request = CertificationRequest::factory()->create();
        Sanctum::actingAs($request->requester);

        $this->deleteJson("/api/animals/{$request->animal_id}")->assertStatus(422);
    }

    public function test_officers_and_guests_cannot_use_farmer_routes()
    {
        $this->getJson('/api/animals')->assertUnauthorized();

        $this->actingAsRole(User::ROLE_OFFICER);
        $this->getJson('/api/animals')->assertForbidden();
        $this->getJson('/api/holdings')->assertForbidden();
    }

    public function test_only_the_owner_can_edit_a_farm_and_a_farm_with_animals_cannot_be_removed()
    {
        $farm = Holding::factory()->create();
        Animal::factory()->for($farm)->create();

        $this->actingAsRole();
        $this->putJson("/api/holdings/{$farm->id}", ['name' => 'Mine now', 'county_id' => 22])->assertForbidden();

        Sanctum::actingAs($farm->owner);
        $this->deleteJson("/api/holdings/{$farm->id}")->assertStatus(422);
    }

    public function test_moving_a_farm_moves_its_animals()
    {
        $farmer = $this->actingAsRole();
        $farm = Holding::factory()->for($farmer, 'owner')->create(['county_id' => 22]);
        $animal = Animal::factory()->for($farm)->create();

        $this->putJson("/api/holdings/{$farm->id}", ['name' => $farm->name, 'county_id' => 32, 'village' => 'Njoro'])->assertOk();

        $this->assertSame(32, $animal->fresh()->county_id);
        $this->assertSame('Njoro', $animal->fresh()->village);
    }
}
