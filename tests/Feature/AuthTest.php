<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email, array $extra = [])
    {
        return $this->postJson('/api/register', [
            'fullname' => 'Wanjiku Kamau',
            'email' => $email,
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ] + $extra);
    }

    public function test_the_first_account_is_the_admin_and_later_ones_are_farmers()
    {
        $this->register('first@example.com')->assertCreated();
        $this->register('second@example.com')->assertCreated();

        $this->assertSame(User::ROLE_ADMIN, User::where('email', 'first@example.com')->value('role'));
        $this->assertSame(User::ROLE_FARMER, User::where('email', 'second@example.com')->value('role'));
    }

    public function test_signup_cannot_choose_its_own_role()
    {
        User::factory()->admin()->create(); // so the next account isn't the first

        $this->register('sneaky@example.com', ['role' => 'admin'])->assertCreated();

        $this->assertSame(User::ROLE_FARMER, User::where('email', 'sneaky@example.com')->value('role'));
    }

    public function test_an_email_can_only_register_once()
    {
        $this->register('dup@example.com')->assertCreated();
        $this->register('dup@example.com')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_returns_a_token_and_role()
    {
        User::factory()->create(['email' => 'farmer@example.com']); // factory password is "password"

        $this->postJson('/api/login', ['email' => 'farmer@example.com', 'password' => 'password'])
            ->assertCreated()
            ->assertJsonStructure(['user', 'token'])
            ->assertJsonPath('role.0', User::ROLE_FARMER);
    }

    public function test_login_with_a_wrong_password_is_refused()
    {
        User::factory()->create(['email' => 'farmer@example.com']);

        $this->postJson('/api/login', ['email' => 'farmer@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
    }

    public function test_me_needs_a_token_and_reports_the_current_role()
    {
        $this->getJson('/api/me')->assertUnauthorized();

        $this->actingAsRole(User::ROLE_OFFICER);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('role.0', User::ROLE_OFFICER);
    }
}
