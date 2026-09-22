<?php

namespace App\Services\Mhlw\Import;

use InvalidArgumentException;
use Normalizer;
use RuntimeException;

/**
 * Builds the medical_facilities.closure_schedule JSON shape from a raw MHLW
 * CSV row + a ClosureScheduleConfig describing where its columns live. Pure
 * array-in/array-out: no DB, no I/O, no framework dependency (only PHP's
 * intl-provided Normalizer, matching App\Services\Text\ItaijiNormalizer's
 * own NFKC approach, but called directly here to keep this class DB-free).
 */
final class ClosureScheduleParser
{
    /**
     * @param  array<int, string>  $row
     * @return array<string, mixed>
     */
    public function parse(array $row, ClosureScheduleConfig $config): array
    {
        $schedule = [
            'weekly' => $this->parseWeekdayFlags($row, $config->weeklyStart),
            'monthly_pattern' => $this->parseMonthlyPattern($row, $config->monthlyPatternStart),
            'holiday' => $this->parseFlag($row[$config->holidayColumnIndex] ?? ''),
            'other_closed_dates' => $this->parseOtherClosedDates($row[$config->otherClosedDatesColumnIndex] ?? ''),
        ];

        if ($config->openWeekdaysStart !== null) {
            $schedule['open_weekdays'] = $this->parseWeekdayFlags($row, $config->openWeekdaysStart, includeHoliday: true);
        }

        if ($config->weeklyHolidaySubColumnIndex !== null) {
            $schedule['weekly_holiday_flag'] = $this->parseFlag($row[$config->weeklyHolidaySubColumnIndex] ?? '');
        }

        return $schedule;
    }

    /**
     * @param  array<int, string>  $row
     * @return array<string, int|null>
     */
    private function parseWeekdayFlags(array $row, int $start, bool $includeHoliday = false): array
    {
        $flags = [];

        foreach (Weekday::week() as $index => $day) {
            $flags[$day->value] = $this->parseFlag($row[$start + $index] ?? '');
        }

        if ($includeHoliday) {
            $flags['holiday'] = $this->parseFlag($row[$start + 7] ?? '');
        }

        return $flags;
    }

    /**
     * @param  array<int, string>  $row
     * @return array<string, array<string, int|null>>
     */
    private function parseMonthlyPattern(array $row, int $start): array
    {
        $pattern = [];

        for ($week = 1; $week <= 5; $week++) {
            $weekStart = $start + (($week - 1) * 7);
            $pattern[(string) $week] = $this->parseWeekdayFlags($row, $weekStart);
        }

        return $pattern;
    }

    /**
     * MHLW's tri-state weekday flag: "0" (closed), "1" (open), or blank
     * (unspecified). Anything else means a column-offset bug in the caller
     * or a genuinely new data quirk -- both deserve a loud failure, not a
     * silently-coerced null.
     */
    private function parseFlag(string $raw): ?int
    {
        $trimmed = trim($raw);

        return match ($trimmed) {
            '' => null,
            '0' => 0,
            '1' => 1,
            default => throw new InvalidArgumentException("Unexpected closure flag value: \"{$raw}\""),
        };
    }

    /**
     * Free text (e.g. "年末年始（12月29日～1月3日）") is never trusted to be a
     * clean delimited list. NFKC-normalize first so full-width digits are
     * matched too, then extract every "M月D日" occurrence regardless of
     * whether the source used commas, ranges, or labels -- range/label
     * semantics are flattened to a flat date list, which is an acceptable
     * lossy simplification as long as it never crashes or drops data
     * silently.
     *
     * @return list<string>
     */
    private function parseOtherClosedDates(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $normalized = Normalizer::normalize($raw, Normalizer::FORM_KC);

        if ($normalized === false) {
            throw new RuntimeException("Failed to NFKC-normalize closure date text: \"{$raw}\"");
        }

        preg_match_all('/(\d{1,2})月(\d{1,2})日/u', $normalized, $matches, PREG_SET_ORDER);

        $dates = array_map(
            fn (array $match): string => sprintf('%02d-%02d', (int) $match[1], (int) $match[2]),
            $matches,
        );

        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }
}
