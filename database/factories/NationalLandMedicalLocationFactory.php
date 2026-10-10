<?php

namespace Database\Factories;

use App\Enums\InstitutionType;
use App\Models\NationalLandMedicalLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NationalLandMedicalLocation>
 */
class NationalLandMedicalLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_type' => InstitutionType::Clinic,
            'municipality_code' => '13101',
            'name_key' => fake()->unique()->company(),
            'address_key' => '千代田区丸の内1-'.fake()->numberBetween(1, 9),
            'latitude' => fake()->latitude(35.67, 35.69),
            'longitude' => fake()->longitude(139.75, 139.77),
        ];
    }
}
