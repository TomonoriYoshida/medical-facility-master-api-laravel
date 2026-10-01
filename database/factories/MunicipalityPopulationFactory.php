<?php

namespace Database\Factories;

use App\Models\MunicipalityPopulation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MunicipalityPopulation>
 */
class MunicipalityPopulationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'municipality_code' => fake()->unique()->numerify('13###'),
            'population' => fake()->numberBetween(1_000, 900_000),
            'as_of' => '2026-01-01',
        ];
    }
}
