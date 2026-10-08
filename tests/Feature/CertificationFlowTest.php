<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Certificate;
use App\Models\CertificationRequest;
use App\Models\Holding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Farmer requests -> admin assigns -> officer decides -> certificate -> public verify / revoke.
class CertificationFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $approval = [
        'approved_breed' => 'Friesian',
        'purity' => 'purebred',
        'inspection_method' => 'photo',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_an_animal_without_the_required_photos_cannot_be_sent()
    {
        $farmer = $this->actingAsRole();
        $animal = Animal::factory()->for(Holding::factory()->for($farmer, 'owner'))->create();

        $this->postJson("/api/animals/{$animal->id}/certification-requests", ['inspection_method' => 'photo'])
            ->assertStatus(422)
            ->assertJsonStructure(['problems']);
    }

    public function test_only_one_open_request_per_animal()
    {
        $farmer = $this->actingAsRole();
        $animal = Animal::factory()->for(Holding::factory()->for($farmer, 'owner'))->readyForCertification()->create();
        $url = "/api/animals/{$animal->id}/certification-requests";

        $this->postJson($url, ['inspection_method' => 'photo'])->assertCreated();
        $this->postJson($url, ['inspection_method' => 'photo'])->assertStatus(422);
    }

    public function test_the_full_journey_from_request_to_verified_certificate()
    {
        $farmer = $this->actingAsRole();
        $animal = Animal::factory()->for(Holding::factory()->for($farmer, 'owner'))->readyForCertification()->create();

        // Farmer asks
        $id = $this->postJson("/api/animals/{$animal->id}/certification-requests", ['inspection_method' => 'photo'])
            ->assertCreated()
            ->json('request.id');

        // Admin assigns a verified officer
        $officer = User::factory()->officer()->create();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson("/api/admin/certification-requests/{$id}/assign", ['officer_id' => $officer->id])
            ->assertOk()
            ->assertJsonPath('request.status', 'assigned');

        // A different officer can't decide it
        Sanctum::actingAs(User::factory()->officer()->create());
        $this->putJson("/api/officer/requests/{$id}/approve", $this->approval)->assertForbidden();

        // The assigned officer approves: the certificate is issued in the same step
        Sanctum::actingAs($officer);
        $this->putJson("/api/officer/requests/{$id}/start")->assertJsonPath('request.status', 'in_review');
        $this->putJson("/api/officer/requests/{$id}/approve", $this->approval)
            ->assertOk()
            ->assertJsonPath('request.status', 'approved')
            ->assertJsonPath('request.certificate.breed', 'Friesian');

        $certificate = Certificate::where('request_id', $id)->firstOrFail();
        $this->assertMatchesRegularExpression('/^MC-KE-\d{4}-\d{6}$/', $certificate->certificate_no);

        // Anyone can verify it, typed loosely, and sees no owner details
        $typed = strtolower(substr($certificate->verification_code, 0, 5) . '-' . substr($certificate->verification_code, 5));
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/verify/{$typed}")
            ->assertOk()
            ->assertJsonPath('certificate.status', 'valid')
            ->assertJsonPath('certificate.animal.tag_number', $animal->tag_number)
            ->assertJsonMissingPath('certificate.owner');

        // A decided request is final
        Sanctum::actingAs($officer);
        $this->putJson("/api/officer/requests/{$id}/reject", ['officer_note' => 'Changed my mind'])->assertStatus(422);

        // Admin revokes; verification shows it
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson("/api/admin/certificates/{$certificate->id}/revoke", ['reason' => 'Issued in error'])->assertOk();
        $this->getJson("/api/verify/{$certificate->verification_code}")->assertJsonPath('certificate.status', 'revoked');
    }

    public function test_an_officer_cannot_be_assigned_their_own_animal()
    {
        $officer = User::factory()->officer()->create();
        $request = CertificationRequest::factory()
            ->for(Animal::factory()->for(Holding::factory()->for($officer, 'owner'))->readyForCertification())
            ->create(['requested_by' => $officer->id]);

        $this->actingAsRole(User::ROLE_ADMIN);
        $this->putJson("/api/admin/certification-requests/{$request->id}/assign", ['officer_id' => $officer->id])
            ->assertStatus(422);
    }

    public function test_needs_info_then_resubmit_returns_to_the_same_officer()
    {
        $officer = User::factory()->officer()->create();
        $request = CertificationRequest::factory()->assignedTo($officer)->create();

        Sanctum::actingAs($officer);
        $this->putJson("/api/officer/requests/{$request->id}/needs-info", ['officer_note' => 'Clearer ear tag photo please'])
            ->assertJsonPath('request.status', 'needs_info');

        Sanctum::actingAs($request->requester);
        $this->putJson("/api/certification-requests/{$request->id}/resubmit", ['owner_note' => 'New photo added'])
            ->assertOk()
            ->assertJsonPath('request.status', 'assigned')
            ->assertJsonPath('request.assigned_officer_id', $officer->id);
    }

    public function test_an_unknown_verification_code_is_not_found()
    {
        $this->getJson('/api/verify/NOPE234567')->assertNotFound();
    }
}
