<?php

namespace App\Policies;

use App\Models\Animal;
use App\Models\User;

// What someone may do with an animal comes from their role on the animal's farm
// (FarmMember::ABILITIES). Animals not on a farm yet (older records) are the owner's alone.
class AnimalPolicy
{
    public function view(User $user, Animal $animal): bool
    {
        return $this->allows($user, $animal, 'herd.view') || $user->hasRole(User::ROLE_ADMIN);
    }

    /** Edit details, photos, move between the owner's farms. */
    public function update(User $user, Animal $animal): bool
    {
        return $this->allows($user, $animal, 'animal.manage');
    }

    public function delete(User $user, Animal $animal): bool
    {
        return $this->allows($user, $animal, 'animal.manage');
    }

    /** Add health / breeding / weight records (workers and vets too). */
    public function logEvent(User $user, Animal $animal): bool
    {
        return $this->allows($user, $animal, 'events.write');
    }

    /** Ask for certification: a paid service, so the owner's decision. */
    public function certify(User $user, Animal $animal): bool
    {
        return $this->allows($user, $animal, 'certify');
    }

    /** For the client: which buttons to show. */
    public function abilities(User $user, Animal $animal): array
    {
        return array_keys(array_filter([
            'update' => $this->update($user, $animal),
            'delete' => $this->delete($user, $animal),
            'log_event' => $this->logEvent($user, $animal),
            'certify' => $this->certify($user, $animal),
        ]));
    }

    private function allows(User $user, Animal $animal, string $ability): bool
    {
        if ($animal->holding_id) {
            return (bool) $animal->holding?->allows($user, $ability);
        }

        return $animal->owner_id === $user->id;
    }
}
