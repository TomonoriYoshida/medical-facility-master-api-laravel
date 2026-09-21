<?php

namespace Database\Factories;

use App\Enums\InstitutionType;
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
        $hasBeds = in_array($institutionType, [InstitutionType::Hospital, InstitutionType::Clinic], true);
        $hasBusinessHours = in_array($institutionType, [InstitutionType::MaternityHome, InstitutionType::Pharmacy], true);

        return [
            'source_id' => fake()->unique()->numerify('#############'),
            'institution_type' => $institutionType,
            'name' => fake()->company().$this->facilitySuffix($institutionType),
            'name_kana' => null,
            'short_name' => null,
            'short_name_kana' => null,
            'name_en' => null,
            'prefecture_code' => fake()->numerify('##'),
            'city_code' => fake()->numerify('###'),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(24, 45),
            'longitude' => fake()->longitude(123, 145),
            'website_url' => fake()->optional()->url(),
            'closure_schedule' => $this->closureSchedule(),
            'business_hours' => $hasBusinessHours ? $this->businessHours() : null,
            'general_beds' => $hasBeds ? fake()->numberBetween(0, 300) : null,
            'sanatorium_beds' => $hasBeds ? fake()->numberBetween(0, 100) : null,
            'sanatorium_beds_medical_insurance' => $hasBeds ? fake()->numberBetween(0, 50) : null,
            'sanatorium_beds_care_insurance' => $hasBeds ? fake()->numberBetween(0, 50) : null,
            'psychiatric_beds' => $institutionType === InstitutionType::Hospital ? fake()->numberBetween(0, 50) : null,
            'tuberculosis_beds' => $institutionType === InstitutionType::Hospital ? fake()->numberBetween(0, 20) : null,
            'infectious_disease_beds' => $institutionType === InstitutionType::Hospital ? fake()->numberBetween(0, 20) : null,
            'total_beds' => $hasBeds ? fake()->numberBetween(0, 500) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function closureSchedule(): array
    {
        $weekly = fn (): array => [
            'mon' => 1,
            'tue' => 1,
            'wed' => 1,
            'thu' => 1,
            'fri' => 1,
            'sat' => fake()->boolean(50) ? 1 : 0,
            'sun' => 0,
        ];

        return [
            'weekly' => $weekly(),
            'monthly_pattern' => collect(range(1, 5))
                ->mapWithKeys(fn (int $week): array => [(string) $week => $weekly()])
                ->all(),
            'holiday' => 0,
            'other_closed_dates' => ['01-01', '01-02', '01-03', '12-29', '12-30', '12-31'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function businessHours(): array
    {
        $slot = fn (): array => [
            ['start' => '09:00', 'end' => '13:00'],
            ['start' => '14:00', 'end' => '18:00'],
        ];

        return [
            'mon' => $slot(),
            'tue' => $slot(),
            'wed' => $slot(),
            'thu' => $slot(),
            'fri' => $slot(),
            'sat' => $slot(),
            'sun' => [],
            'holiday' => [],
        ];
    }

    private function facilitySuffix(InstitutionType $type): string
    {
        return match ($type) {
            InstitutionType::Hospital => '病院',
            InstitutionType::Clinic => 'クリニック',
            InstitutionType::DentalClinic => '歯科医院',
            InstitutionType::MaternityHome => '助産院',
            InstitutionType::Pharmacy => '薬局',
        };
    }
}
