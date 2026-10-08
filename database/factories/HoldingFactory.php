<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Holding>
 */
class HoldingFactory extends Factory
{
    public function definition()
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->lastName() . ' Farm',
            'county_id' => 22, // Kiambu
            'village' => fake()->city(),
            'latitude' => fake()->randomFloat(7, -1.3, -0.9),
            'longitude' => fake()->randomFloat(7, 36.6, 37.0),
            'location_source' => 'gps',
        ];
    }
}
