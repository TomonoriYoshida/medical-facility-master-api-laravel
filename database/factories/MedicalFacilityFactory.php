<?php

namespace Database\Factories;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use App\Models\MedicalFacility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalFacility>
 */
class MedicalFacilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $institutionType = fake()->randomElement(InstitutionType::cases());
        $hasDepartments = $institutionType !== InstitutionType::Pharmacy;

        return [
            'facility_code' => fake()->unique()->numerify('#######'),
            'bureau_code' => RhbBureau::Hokkaido,
            'institution_type' => $institutionType,
            'status' => MedicalFacilityStatus::Active,
            'name' => fake()->company().$this->facilitySuffix($institutionType),
            'prefecture_code' => '01',
            'postal_code' => fake()->numerify('###-####'),
            'address' => fake()->address(),
            'latitude' => null,
            'longitude' => null,
            'phone_number' => fake()->numerify('0##-###-####'),
            'founder_name' => fake()->company(),
            'administrator_name' => fake()->name(),
            'designated_on' => fake()->date(),
            'designation_history' => [],
            'bed_counts' => $hasDepartments ? ['general' => fake()->numberBetween(0, 300)] : null,
            'department_categories' => $hasDepartments
                ? fake()->randomElements(DepartmentBaseCategory::cases(), fake()->numberBetween(1, 3))
                : [],
        ];
    }

    private function facilitySuffix(InstitutionType $type): string
    {
        return match ($type) {
            InstitutionType::Hospital => '病院',
            InstitutionType::Clinic => 'クリニック',
            InstitutionType::DentalClinic => '歯科医院',
            InstitutionType::Pharmacy => '薬局',
        };
    }
}
