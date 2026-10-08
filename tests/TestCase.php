<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    // Tests using RefreshDatabase get the reference data (counties) seeded after migrating
    protected bool $seed = true;

    protected function actingAsRole(string $role = User::ROLE_FARMER): User
    {
        $user = match ($role) {
            User::ROLE_ADMIN => User::factory()->admin()->create(),
            User::ROLE_OFFICER => User::factory()->officer()->create(),
            default => User::factory()->create(),
        };
        Sanctum::actingAs($user);

        return $user;
    }
}
