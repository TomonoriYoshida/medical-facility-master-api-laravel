<?php

namespace Tests\Unit\Services\MedicalInfoNet;

use App\Services\MedicalInfoNet\OpeningPeriods;
use PHPUnit\Framework\TestCase;

class OpeningPeriodsTest extends TestCase
{
    public function test_reception_hours_are_used_when_listed_and_consultation_hours_otherwise(): void
    {
        $periods = (new OpeningPeriods)->fromSchedules([
            $this->schedule([
                // Reception for the whole day on the morning slot only.
                [['mon', '09:00', '12:00', '08:30', '17:30']],
                [['mon', '14:00', '18:00', null, null], ['tue', '14:00', '18:00', null, null]],
            ]),
        ], null);

        $this->assertSame([
            ['day' => 1, 'opens' => '08:30:00', 'closes' => '17:30:00', 'weeks' => 31],
            ['day' => 2, 'opens' => '14:00:00', 'closes' => '18:00:00', 'weeks' => 31],
        ], $periods);
    }

    public function test_departments_ranges_are_merged_per_day(): void
    {
        $periods = (new OpeningPeriods)->fromSchedules([
            $this->schedule([[['wed', '09:00', '12:00', null, null]], [['wed', '15:00', '18:00', null, null]]]),
            $this->schedule([[['wed', '11:00', '13:00', null, null]], [['wed', '18:00', '19:00', null, null]]]),
        ], null);

        $this->assertSame([
            ['day' => 3, 'opens' => '09:00:00', 'closes' => '13:00:00', 'weeks' => 31],
            ['day' => 3, 'opens' => '15:00:00', 'closes' => '19:00:00', 'weeks' => 31],
        ], $periods);
    }

    public function test_a_range_past_midnight_runs_into_the_next_days_early_hours(): void
    {
        $periods = (new OpeningPeriods)->fromSchedules([
            $this->schedule([[['sun', '22:00', '06:00', null, null], ['holiday', '20:00', '08:00', null, null]]]),
        ], null);

        $this->assertSame([
            ['day' => 1, 'opens' => '00:00:00', 'closes' => '06:00:00', 'weeks' => 31],
            ['day' => 7, 'opens' => '22:00:00', 'closes' => '24:00:00', 'weeks' => 31],
            // The day after a holiday is not known to be one; nothing carries over.
            ['day' => 8, 'opens' => '20:00:00', 'closes' => '24:00:00', 'weeks' => 31],
        ], $periods);
    }

    public function test_days_off_remove_ranges_or_leave_out_weeks(): void
    {
        $periods = (new OpeningPeriods)->fromSchedules([
            $this->schedule([[
                ['wed', '09:00', '12:00', null, null],
                ['thu', '09:00', '12:00', null, null],
                ['sat', '09:00', '12:00', null, null],
                ['holiday', '09:00', '12:00', null, null],
            ]]),
        ], [
            'weekly' => ['thu'],
            'monthly' => [['week' => 2, 'day' => 'wed'], ['week' => 4, 'day' => 'wed'], ['week' => 5, 'day' => 'sat']],
            'holidays' => true,
            'other' => '年末年始',
        ]);

        $this->assertSame([
            ['day' => 3, 'opens' => '09:00:00', 'closes' => '12:00:00', 'weeks' => 0b10101],
            ['day' => 6, 'opens' => '09:00:00', 'closes' => '12:00:00', 'weeks' => 0b01111],
        ], $periods);
    }

    public function test_a_pharmacys_hours_without_reception_are_used(): void
    {
        $periods = (new OpeningPeriods)->fromSchedules([
            ['departments' => [], 'slots' => [['number' => 1, 'days' => [
                ['day' => 'fri', 'opens' => '09:00', 'closes' => '24:00', 'reception_opens' => null, 'reception_closes' => null],
            ]]]],
        ], null);

        $this->assertSame([['day' => 5, 'opens' => '09:00:00', 'closes' => '24:00:00', 'weeks' => 31]], $periods);
    }

    /**
     * @param  list<list<array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}>>  $slots  per slot: [day, opens, closes, reception_opens, reception_closes]
     * @return array{departments: list<string>, slots: list<array{number: int, days: list<array{day: string, opens: ?string, closes: ?string, reception_opens: ?string, reception_closes: ?string}>}>}
     */
    private function schedule(array $slots): array
    {
        return [
            'departments' => ['内科'],
            'slots' => array_map(fn (array $days, int $index): array => [
                'number' => $index + 1,
                'days' => array_map(fn (array $day): array => [
                    'day' => $day[0],
                    'opens' => $day[1],
                    'closes' => $day[2],
                    'reception_opens' => $day[3],
                    'reception_closes' => $day[4],
                ], $days),
            ], $slots, array_keys($slots)),
        ];
    }
}
