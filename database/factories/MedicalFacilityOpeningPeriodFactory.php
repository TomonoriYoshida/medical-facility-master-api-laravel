<?php

namespace Database\Factories;

use App\Models\MedicalFacility;
use App\Models\MedicalFacilityOpeningPeriod;
use App\Services\MedicalInfoNet\OpeningPeriods;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalFacilityOpeningPeriod>
 */
class MedicalFacilityOpeningPeriodFactory extends Factory
{
    /**
     * Define the model's default state: Monday mornings, every week.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medical_facility_id' => MedicalFacility::factory(),
            'day' => 1,
            'opens' => '09:00:00',
            'closes' => '12:00:00',
            'weeks' => OpeningPeriods::ALL_WEEKS,
        ];
    }
}
