<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OfficerProfile>
 */
class OfficerProfileFactory extends Factory
{
    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'licence_number' => 'KVB/' . fake()->unique()->numerify('#####'),
            'cadre' => 'vet',
            'phone' => '07' . fake()->numerify('########'),
            'county_id' => 22, // Kiambu
            'status' => 'pending',
        ];
    }

    public function approved()
    {
        return $this->state(['status' => 'approved', 'verified_at' => now()]);
    }
}
