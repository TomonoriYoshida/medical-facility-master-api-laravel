<?php

namespace App\Services\MedicalInfoNet;

/**
 * Turns a facility's 医療情報ネット hours into the ranges it can be visited
 * in, one row per day and range (medical_facility_opening_periods), merged
 * across departments: a facility is open when any of its departments is.
 *
 * - Per department and day, the reception hours are used when any are
 *   listed, else the consultation hours (most slots list only those).
 * - A range closing earlier than it opens runs past midnight, into the
 *   next day's early hours (a holiday's, into nothing: the next day is not
 *   known to be one).
 * - The days off are applied conservatively: a day off every week, or
 *   holidays off, removes that day's ranges even if hours are listed for
 *   it; a day off in given weeks (第2水曜) leaves those weeks out of the
 *   day's ranges. Free-text days off (年末年始) are not read.
 */
final class OpeningPeriods
{
    /**
     * Day names => the numbers stored: ISO 8601 weekdays, 8 for public holidays.
     */
    public const array DAY_NUMBERS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7, 'holiday' => 8];

    public const int HOLIDAY = 8;

    /** Every week of the month (bits 0-4). */
    public const int ALL_WEEKS = 0b11111;

    private const int MINUTES_PER_DAY = 1440;

    /**
     * @param  list<array{departments: list<string>, slots: list<array{number: int, days: list<array{day: string, opens: ?string, closes: ?string, reception_opens: ?string, reception_closes: ?string}>}>}>  $schedules
     * @param  array{weekly: list<string>, monthly: list<array{week: int, day: string}>, holidays: ?bool, other: ?string}|null  $closures
     * @return list<array{day: int, opens: string, closes: string, weeks: int}>
     */
    public function fromSchedules(array $schedules, ?array $closures): array
    {
        $ranges = [];

        foreach ($schedules as $schedule) {
            foreach (self::DAY_NUMBERS as $day => $number) {
                $entries = [];

                foreach ($schedule['slots'] as $slot) {
                    foreach ($slot['days'] as $entry) {
                        if ($entry['day'] === $day) {
                            $entries[] = $entry;
                        }
                    }
                }

                foreach ($this->windows($entries) as [$opens, $closes]) {
                    if ($closes > $opens) {
                        $ranges[$number][] = [$opens, $closes];
                    } elseif ($closes < $opens) {
                        $ranges[$number][] = [$opens, self::MINUTES_PER_DAY];

                        if ($number !== self::HOLIDAY && $closes > 0) {
                            $ranges[$number % 7 + 1][] = [0, $closes];
                        }
                    }
                }
            }
        }

        foreach ($closures['weekly'] ?? [] as $day) {
            unset($ranges[self::DAY_NUMBERS[$day]]);
        }

        if (($closures['holidays'] ?? null) === true) {
            unset($ranges[self::HOLIDAY]);
        }

        $weeks = [];

        foreach ($closures['monthly'] ?? [] as $closure) {
            $number = self::DAY_NUMBERS[$closure['day']];
            $weeks[$number] = ($weeks[$number] ?? self::ALL_WEEKS) & ~(1 << ($closure['week'] - 1));
        }

        ksort($ranges);
        $periods = [];

        foreach ($ranges as $number => $dayRanges) {
            foreach ($this->merge($dayRanges) as [$opens, $closes]) {
                $periods[] = [
                    'day' => $number,
                    'opens' => $this->time($opens),
                    'closes' => $this->time($closes),
                    'weeks' => $weeks[$number] ?? self::ALL_WEEKS,
                ];
            }
        }

        return $periods;
    }

    /**
     * One department's ranges on one day, in minutes since midnight.
     *
     * @param  list<array{day: string, opens: ?string, closes: ?string, reception_opens: ?string, reception_closes: ?string}>  $entries
     * @return list<array{int, int}>
     */
    private function windows(array $entries): array
    {
        $reception = [];
        $hours = [];

        foreach ($entries as $entry) {
            if ($entry['reception_opens'] !== null && $entry['reception_closes'] !== null) {
                $reception[] = [$this->minutes($entry['reception_opens']), $this->minutes($entry['reception_closes'])];
            }

            if ($entry['opens'] !== null && $entry['closes'] !== null) {
                $hours[] = [$this->minutes($entry['opens']), $this->minutes($entry['closes'])];
            }
        }

        return $reception !== [] ? $reception : $hours;
    }

    /**
     * Overlapping and touching ranges joined, in order.
     *
     * @param  list<array{int, int}>  $ranges
     * @return list<array{int, int}>
     */
    private function merge(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b): int => $a <=> $b);
        $merged = [];

        foreach ($ranges as [$opens, $closes]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $opens <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $closes);
            } else {
                $merged[] = [$opens, $closes];
            }
        }

        return $merged;
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }

    private function time(int $minutes): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }
}
