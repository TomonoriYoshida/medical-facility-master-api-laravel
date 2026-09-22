<?php

namespace Tests\Unit\Services\Mhlw\Import;

use App\Services\Mhlw\Import\TimeSlotParser;
use PHPUnit\Framework\TestCase;

class TimeSlotParserTest extends TestCase
{
    public function test_parse_band_reads_start_end_pairs_per_day_and_omits_blank_slots(): void
    {
        $row = [
            '09:00', '12:00', // mon
            '09:00', '12:00', // tue
            '', '', // wed (blank)
            '10:00', '15:00', // thu
            '', '', // fri (blank)
            '', '', // sat (blank)
            '', '', // sun (blank)
            '', '', // holiday (blank)
        ];

        $result = (new TimeSlotParser)->parseBand($row, 0, includeHoliday: true);

        $this->assertSame(['start' => '09:00', 'end' => '12:00'], $result['mon']);
        $this->assertSame(['start' => '09:00', 'end' => '12:00'], $result['tue']);
        $this->assertNull($result['wed']);
        $this->assertSame(['start' => '10:00', 'end' => '15:00'], $result['thu']);
        $this->assertNull($result['fri']);
        $this->assertNull($result['sat']);
        $this->assertNull($result['sun']);
        $this->assertNull($result['holiday']);
    }

    public function test_parse_band_without_holiday_omits_the_holiday_key(): void
    {
        $row = array_fill(0, 14, '');

        $result = (new TimeSlotParser)->parseBand($row, 0, includeHoliday: false);

        $this->assertArrayNotHasKey('holiday', $result);
        $this->assertCount(7, $result);
    }

    public function test_parse_bands_merges_multiple_bands_for_the_same_day(): void
    {
        // band 1 (8 days x 2 cols = 16 cols): mon = 09:00-12:00, rest blank
        $band1 = array_fill(0, 16, '');
        $band1[0] = '09:00';
        $band1[1] = '12:00';

        // band 2: mon = 14:00-18:00, rest blank
        $band2 = array_fill(0, 16, '');
        $band2[0] = '14:00';
        $band2[1] = '18:00';

        $row = [...$band1, ...$band2];

        $result = (new TimeSlotParser)->parseBands($row, 0, bandCount: 2, includeHoliday: true);

        $this->assertSame(
            [
                ['start' => '09:00', 'end' => '12:00'],
                ['start' => '14:00', 'end' => '18:00'],
            ],
            $result['mon'],
        );
        $this->assertSame([], $result['tue']);
    }

    public function test_empty_week_has_all_days_as_empty_arrays(): void
    {
        $result = (new TimeSlotParser)->emptyWeek(includeHoliday: true);

        $this->assertSame(
            ['mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [], 'holiday' => []],
            $result,
        );
    }

    public function test_merge_band_into_skips_null_slots(): void
    {
        $parser = new TimeSlotParser;
        $accumulator = $parser->emptyWeek(includeHoliday: false);

        $parser->mergeBandInto($accumulator, [
            'mon' => ['start' => '09:00', 'end' => '12:00'],
            'tue' => null,
        ] + array_fill_keys(['wed', 'thu', 'fri', 'sat', 'sun'], null));

        $this->assertSame([['start' => '09:00', 'end' => '12:00']], $accumulator['mon']);
        $this->assertSame([], $accumulator['tue']);
    }
}
