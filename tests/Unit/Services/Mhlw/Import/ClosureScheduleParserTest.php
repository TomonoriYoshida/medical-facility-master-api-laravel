<?php

namespace Tests\Unit\Services\Mhlw\Import;

use App\Services\Mhlw\Import\ClosureScheduleConfig;
use App\Services\Mhlw\Import\ClosureScheduleParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ClosureScheduleParserTest extends TestCase
{
    public function test_it_parses_the_standard_44_column_closure_shape(): void
    {
        $row = $this->buildRow(57, [
            13 => '1', 14 => '1', 15 => '1', 16 => '1', 17 => '1', 18 => '0', 19 => '0',
            55 => '0',
            56 => '年末年始（12月29日～1月3日）',
        ]);

        for ($i = 20; $i <= 26; $i++) {
            $row[$i] = '1';
        }

        $result = (new ClosureScheduleParser)->parse($row, ClosureScheduleConfig::standard());

        $this->assertSame(
            ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 1, 'fri' => 1, 'sat' => 0, 'sun' => 0],
            $result['weekly'],
        );
        $this->assertSame(
            ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 1, 'fri' => 1, 'sat' => 1, 'sun' => 1],
            $result['monthly_pattern']['1'],
        );
        $this->assertCount(5, $result['monthly_pattern']);
        $this->assertSame(0, $result['holiday']);
        $this->assertSame(['01-03', '12-29'], $result['other_closed_dates']);
        $this->assertArrayNotHasKey('open_weekdays', $result);
        $this->assertArrayNotHasKey('weekly_holiday_flag', $result);
    }

    public function test_it_parses_the_pharmacy_53_column_closure_shape_with_extra_keys(): void
    {
        $row = $this->buildRow(64, [
            11 => '1', 12 => '1', 13 => '1', 14 => '1', 15 => '1', 16 => '1', 17 => '0', 18 => '0',
            19 => '1', 20 => '1', 21 => '1', 22 => '1', 23 => '1', 24 => '0', 25 => '0',
            26 => '0',
            62 => '0',
            63 => '',
        ]);

        $result = (new ClosureScheduleParser)->parse($row, ClosureScheduleConfig::pharmacy());

        $this->assertSame(
            ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 1, 'fri' => 1, 'sat' => 1, 'sun' => 0, 'holiday' => 0],
            $result['open_weekdays'],
        );
        $this->assertSame(
            ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 1, 'fri' => 1, 'sat' => 0, 'sun' => 0],
            $result['weekly'],
        );
        $this->assertSame(0, $result['weekly_holiday_flag']);
        $this->assertSame(0, $result['holiday']);
    }

    public function test_blank_flag_becomes_null(): void
    {
        $row = $this->buildRow(57, [13 => '']);

        $result = (new ClosureScheduleParser)->parse($row, ClosureScheduleConfig::standard());

        $this->assertNull($result['weekly']['mon']);
    }

    public function test_unexpected_flag_value_throws(): void
    {
        $row = $this->buildRow(57, [13 => '2']);

        $this->expectException(InvalidArgumentException::class);

        (new ClosureScheduleParser)->parse($row, ClosureScheduleConfig::standard());
    }

    public function test_free_text_dates_handle_full_width_digits(): void
    {
        $row = $this->buildRow(57, [56 => '１２月２９日、１月３日']);

        $result = (new ClosureScheduleParser)->parse($row, ClosureScheduleConfig::standard());

        $this->assertSame(['01-03', '12-29'], $result['other_closed_dates']);
    }

    public function test_free_text_dates_are_deduplicated_and_sorted(): void
    {
        $row = $this->buildRow(57, [56 => '12月29日、12月29日、1月1日']);

        $result = (new ClosureScheduleParser)->parse($row, ClosureScheduleConfig::standard());

        $this->assertSame(['01-01', '12-29'], $result['other_closed_dates']);
    }

    public function test_empty_free_text_yields_no_dates(): void
    {
        $row = $this->buildRow(57, [56 => '']);

        $result = (new ClosureScheduleParser)->parse($row, ClosureScheduleConfig::standard());

        $this->assertSame([], $result['other_closed_dates']);
    }

    /**
     * @param  array<int, string>  $overrides
     * @return array<int, string>
     */
    private function buildRow(int $length, array $overrides): array
    {
        $row = array_fill(0, $length, '');

        foreach ($overrides as $index => $value) {
            $row[$index] = $value;
        }

        return $row;
    }
}
