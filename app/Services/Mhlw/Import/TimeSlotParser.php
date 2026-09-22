<?php

namespace App\Services\Mhlw\Import;

/**
 * Builds "day => [{start, end}, ...]" hour maps (the shape shared by
 * business_hours/reception_hours and department consultation_hours/
 * reception_hours) from raw MHLW CSV columns. Two source shapes exist in
 * the real data: a "wide" block where every time band is its own group of
 * columns in the same row (maternity_home/pharmacy), and a "banded" shape
 * where each row carries exactly one band and multiple rows must be merged
 * externally (hospital/clinic/dental speciality files, merged by
 * SpecialityRowGrouper/SpecialityRowMapper) -- parseBand()/mergeBandInto()
 * support both by making the wide case just an internal loop over the same
 * single-band primitive.
 */
final class TimeSlotParser
{
    private const int COLUMNS_PER_DAY = 2;

    /**
     * @param  array<int, string>  $row
     * @return array<string, list<array{start: string, end: string}>>
     */
    public function parseBands(array $row, int $start, int $bandCount, bool $includeHoliday): array
    {
        $accumulator = $this->emptyWeek($includeHoliday);
        $columnsPerBand = count($this->dayKeys($includeHoliday)) * self::COLUMNS_PER_DAY;

        for ($band = 0; $band < $bandCount; $band++) {
            $bandStart = $start + ($band * $columnsPerBand);
            $this->mergeBandInto($accumulator, $this->parseBand($row, $bandStart, $includeHoliday));
        }

        return $accumulator;
    }

    /**
     * Parse a single band's worth of "(7|8 days) x (start, end)" columns.
     *
     * @param  array<int, string>  $row
     * @return array<string, array{start: string, end: string}|null>
     */
    public function parseBand(array $row, int $start, bool $includeHoliday): array
    {
        $result = [];

        foreach ($this->dayKeys($includeHoliday) as $index => $day) {
            $columnStart = $start + ($index * self::COLUMNS_PER_DAY);
            $startTime = trim($row[$columnStart] ?? '');
            $endTime = trim($row[$columnStart + 1] ?? '');

            $result[$day] = ($startTime !== '' && $endTime !== '')
                ? ['start' => $startTime, 'end' => $endTime]
                : null;
        }

        return $result;
    }

    /**
     * @return array<string, list<array{start: string, end: string}>>
     */
    public function emptyWeek(bool $includeHoliday): array
    {
        return array_fill_keys($this->dayKeys($includeHoliday), []);
    }

    /**
     * @param  array<string, list<array{start: string, end: string}>>  $accumulator
     * @param  array<string, array{start: string, end: string}|null>  $band
     */
    public function mergeBandInto(array &$accumulator, array $band): void
    {
        foreach ($band as $day => $slot) {
            if ($slot !== null) {
                $accumulator[$day][] = $slot;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function dayKeys(bool $includeHoliday): array
    {
        $keys = array_map(fn (Weekday $day): string => $day->value, Weekday::week());

        if ($includeHoliday) {
            $keys[] = 'holiday';
        }

        return $keys;
    }
}
