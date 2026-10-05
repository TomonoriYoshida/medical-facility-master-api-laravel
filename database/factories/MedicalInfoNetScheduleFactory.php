<?php

namespace Database\Factories;

use App\Models\MedicalInfoNetSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalInfoNetSchedule>
 */
class MedicalInfoNetScheduleFactory extends Factory
{
    /**
     * Define the model's default state: 内科 open weekday mornings and
     * afternoons, as MedicalInfoNetHours groups them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $weekdays = fn (string $opens, string $closes, string $receptionOpens, string $receptionCloses): array => array_map(
            fn (string $day): array => ['day' => $day, 'opens' => $opens, 'closes' => $closes, 'reception_opens' => $receptionOpens, 'reception_closes' => $receptionCloses],
            ['mon', 'tue', 'wed', 'thu', 'fri'],
        );

        return [
            'source_id' => (string) fake()->unique()->numerify('13#########'),
            'schedules' => [[
                'departments' => ['内科'],
                'slots' => [
                    ['number' => 1, 'days' => $weekdays('09:00', '12:30', '08:45', '12:00')],
                    ['number' => 2, 'days' => $weekdays('14:00', '18:00', '14:00', '17:30')],
                ],
            ]],
        ];
    }
}
