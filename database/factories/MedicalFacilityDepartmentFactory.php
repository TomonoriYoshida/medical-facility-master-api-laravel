<?php

namespace Database\Factories;

use App\Models\MedicalFacility;
use App\Models\MedicalFacilityDepartment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalFacilityDepartment>
 */
class MedicalFacilityDepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medical_facility_id' => MedicalFacility::factory(),
            'department_code' => fake()->unique()->numerify('#####'),
            'department_name' => fake()->randomElement([
                '内科', '外科', '小児科', '皮膚科', '整形外科',
                '眼科', '耳鼻咽喉科', '産婦人科', '精神科', '泌尿器科',
            ]),
            'consultation_hours' => $this->weeklyHours(),
            'reception_hours' => $this->weeklyHours(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function weeklyHours(): array
    {
        $slot = fn (): ?array => fake()->boolean(80)
            ? ['start' => '09:00', 'end' => '17:30']
            : null;

        return [
            'mon' => $slot(),
            'tue' => $slot(),
            'wed' => $slot(),
            'thu' => $slot(),
            'fri' => $slot(),
            'sat' => $slot(),
            'sun' => null,
            'holiday' => null,
        ];
    }
}
