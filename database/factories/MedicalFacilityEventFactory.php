<?php

namespace Database\Factories;

use App\Enums\MedicalFacilityEventType;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalFacilityEvent>
 */
class MedicalFacilityEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventType = fake()->randomElement(MedicalFacilityEventType::cases());

        return [
            'medical_facility_id' => MedicalFacility::factory(),
            'department_code' => null,
            'event_type' => $eventType,
            'occurred_on' => fake()->dateTimeBetween('-1 year', 'now'),
            'payload' => match ($eventType) {
                MedicalFacilityEventType::Created => ['name' => fake()->company()],
                MedicalFacilityEventType::Removed => ['name' => fake()->company()],
                MedicalFacilityEventType::Updated => [
                    'name' => ['old' => fake()->company(), 'new' => fake()->company()],
                ],
            },
            'mhlw_dataset_download_id' => null,
        ];
    }
}
