<?php

namespace Database\Factories;

use App\Models\Animal;
use App\Models\AnimalPhoto;
use App\Models\Holding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Animal>
 */
class AnimalFactory extends Factory
{
    public function definition()
    {
        // holding_id first: the closures below read the farm it resolves to
        return [
            'holding_id' => Holding::factory(),
            'owner_id' => fn (array $a) => Holding::find($a['holding_id'])->owner_id,
            'species' => 'cattle',
            'tag_number' => 'KE-' . fake()->unique()->numerify('######'),
            'name' => fake()->firstName('female'),
            'sex' => 'female',
            'date_of_birth' => fake()->dateTimeBetween('-8 years', '-2 years')->format('Y-m-d'),
            'purpose' => 'dairy',
            'breed_claimed' => 'Friesian',
            'county_id' => fn (array $a) => Holding::find($a['holding_id'])->county_id,
        ];
    }

    /** Has the photos certification needs (left, right, ear tag). Files are not created. */
    public function readyForCertification()
    {
        return $this->afterCreating(function (Animal $animal) {
            foreach (['left', 'right', 'ear_tag'] as $angle) {
                AnimalPhoto::create([
                    'animal_id' => $animal->id,
                    'angle' => $angle,
                    'path' => "animals/{$animal->id}/{$angle}.jpg",
                    'image_hash' => hash('sha256', $animal->id . $angle),
                ]);
            }
        });
    }
}
