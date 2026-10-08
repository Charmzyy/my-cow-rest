<?php

namespace App\Policies;

use App\Models\Animal;
use App\Models\User;

// Owners manage their own animals; platform admins can see everything.
// (Officers get access to animals assigned to them in Phase 1B.)
class AnimalPolicy
{
    public function view(User $user, Animal $animal): bool
    {
        return $animal->owner_id === $user->id || $user->hasRole(User::ROLE_ADMIN);
    }

    public function update(User $user, Animal $animal): bool
    {
        return $animal->owner_id === $user->id;
    }

    public function delete(User $user, Animal $animal): bool
    {
        return $animal->owner_id === $user->id;
    }
}
