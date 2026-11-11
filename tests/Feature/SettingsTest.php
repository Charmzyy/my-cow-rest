<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\OrganisationMember;
use App\Models\User;
use App\Support\Contrast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Personal settings (theme, accent, chart colours, saved charts) and workspace branding.
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_a_new_account_gets_the_defaults()
    {
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('preferences.theme', 'light')
            ->assertJsonPath('preferences.accent', null)
            ->assertJsonPath('preferences.chart_palette', 'farm')
            ->assertJsonMissingPath('user.preferences');
    }

    public function test_an_update_changes_only_what_was_sent_and_drops_unknown_keys()
    {
        $this->putJson('/api/me/preferences', ['theme' => 'dark', 'favourite_cow' => 'Daisy'])
            ->assertOk()
            ->assertJsonPath('preferences.theme', 'dark')
            ->assertJsonPath('preferences.chart_palette', 'farm')
            ->assertJsonMissingPath('preferences.favourite_cow');

        $this->putJson('/api/me/preferences', ['chart_palette' => 'colorblind'])
            ->assertOk()
            ->assertJsonPath('preferences.theme', 'dark');

        $this->assertSame(['theme', 'accent', 'chart_palette', 'dashboards'], array_keys($this->user->fresh()->preferences));
    }

    public function test_a_light_accent_is_refused_because_white_text_on_it_is_unreadable()
    {
        $this->putJson('/api/me/preferences', ['accent' => '#f7c823'])->assertStatus(422)->assertJsonValidationErrors('accent');
        $this->putJson('/api/me/preferences', ['accent' => 'green'])->assertStatus(422)->assertJsonValidationErrors('accent');
        $this->putJson('/api/me/preferences', ['accent' => '#1d4ed8'])->assertOk()->assertJsonPath('preferences.accent', '#1d4ed8');
        $this->putJson('/api/me/preferences', ['accent' => null])->assertOk()->assertJsonPath('preferences.accent', null);
    }

    public function test_chart_choices_are_saved_per_card_and_can_be_reset()
    {
        $card = ['metric' => 'milk.produced', 'group' => 'time', 'range' => '90', 'granularity' => 'week', 'type' => 'line', 'compare' => true];
        $this->putJson('/api/me/preferences', ['dashboards' => ['farm-milk' => $card]])
            ->assertOk()
            ->assertJsonPath('preferences.dashboards.farm-milk.type', 'line');

        $this->putJson('/api/me/preferences', ['dashboards' => ['farm-herd' => ['metric' => 'herd.composition', 'group' => 'breed', 'type' => 'pie']]])
            ->assertOk()
            ->assertJsonPath('preferences.dashboards.farm-milk.granularity', 'week');   // the other card is untouched

        $this->putJson('/api/me/preferences', ['dashboards' => ['farm-milk' => null]])
            ->assertOk()
            ->assertJsonMissingPath('preferences.dashboards.farm-milk');

        $this->putJson('/api/me/preferences', ['dashboards' => ['farm-milk' => ['metric' => 'milk.secret', 'group' => 'time']]])->assertStatus(422);
        $this->putJson('/api/me/preferences', ['dashboards' => ['Bad Name!' => $card]])->assertStatus(422);
    }

    public function test_the_profile_name_and_phone_can_be_changed()
    {
        $this->putJson('/api', ['fullname' => '  Wanjiku Kamau ', 'phone' => '0712345678'])
            ->assertOk()
            ->assertJsonPath('user.fullname', 'Wanjiku Kamau')
            ->assertJsonPath('user.phone', '0712345678');
    }

    public function test_contrast_maths_matches_the_wcag_examples()
    {
        $this->assertEquals(21.0, Contrast::ratio('#000000', '#ffffff'));
        $this->assertTrue(Contrast::okForWhiteText('#1b5e37'));    // MyCow green, 7.6:1
        $this->assertFalse(Contrast::okForWhiteText('#e0a526'));   // savanna gold: dark ink only
    }

    // ---- Workspace branding --------------------------------------------------------------------

    /** A real 1x1 PNG. UploadedFile::fake()->image() needs GD, which the app image doesn't have. */
    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));
    }

    private function coop(): Organisation
    {
        $id = $this->postJson('/api/orgs', ['name' => 'Githunguri Dairy Co-op', 'type' => 'coop'])->assertCreated()->json('organisation.id');

        return Organisation::findOrFail($id);
    }

    public function test_owners_set_a_readable_brand_colour_and_a_logo()
    {
        Storage::fake(config('filesystems.default'));
        $org = $this->coop();

        $this->post("/api/orgs/{$org->id}/branding", ['brand_color' => '#ffd54f'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('brand_color');

        $res = $this->post("/api/orgs/{$org->id}/branding", [
            'brand_color' => '#7B1FA2',
            'logo' => $this->png(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('organisation.brand_color', '#7b1fa2');

        $logoUrl = $res->json('organisation.logo_url');
        $this->assertStringStartsWith("/orgs/{$org->id}/logo?v=", $logoUrl);
        $this->get("/api{$logoUrl}")->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->post("/api/orgs/{$org->id}/branding", ['remove_logo' => 1, 'brand_color' => ''], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('organisation.logo_url', null)
            ->assertJsonPath('organisation.brand_color', null);
        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles("org-logos/{$org->id}"));
    }

    public function test_plain_members_cannot_rebrand_the_workspace()
    {
        $org = $this->coop();
        $member = User::factory()->create();
        $org->addMember($member, OrganisationMember::MEMBER);
        Sanctum::actingAs($member);

        $this->post("/api/orgs/{$org->id}/branding", ['brand_color' => '#1b5e37'], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_svg_logos_are_refused()
    {
        $org = $this->coop();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->post("/api/orgs/{$org->id}/branding", ['logo' => $svg], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('logo');
    }
}
