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
        return [
            'mon' => $this->timeSlots(),
            'tue' => $this->timeSlots(),
            'wed' => $this->timeSlots(),
            'thu' => $this->timeSlots(),
            'fri' => $this->timeSlots(),
            'sat' => $this->timeSlots(),
            'sun' => [],
            'holiday' => [],
        ];
    }

    /**
     * Real MHLW speciality data routinely has more than one time band per
     * day (診療時間帯 1/2/3, e.g. a morning/afternoon split) rather than a
     * single slot, so this mirrors that with multiple {start, end} entries.
     *
     * @return list<array<string, string>>
     */
    private function timeSlots(): array
    {
        if (! fake()->boolean(80)) {
            return [];
        }

        $slots = [['start' => '09:00', 'end' => '12:00']];

        if (fake()->boolean(45)) {
            $slots[] = ['start' => '14:00', 'end' => '17:30'];
        }

        return $slots;
    }
}
