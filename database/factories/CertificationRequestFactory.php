<?php

namespace Database\Factories;

use App\Models\Animal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CertificationRequest>
 */
class CertificationRequestFactory extends Factory
{
    public function definition()
    {
        return [
            'animal_id' => Animal::factory()->readyForCertification(),
            'requested_by' => fn (array $a) => Animal::find($a['animal_id'])->owner_id,
            'status' => 'submitted',
            'inspection_method' => 'photo',
        ];
    }

    public function assignedTo(User $officer)
    {
        return $this->state(['status' => 'assigned', 'assigned_officer_id' => $officer->id, 'assigned_at' => now()]);
    }
}
