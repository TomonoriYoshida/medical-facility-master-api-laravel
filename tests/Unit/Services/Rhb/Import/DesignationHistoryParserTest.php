<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Services\Rhb\Import\DesignationHistoryParser;
use PHPUnit\Framework\TestCase;

class DesignationHistoryParserTest extends TestCase
{
    public function test_a_record_with_only_a_designation_date_has_no_history_entries(): void
    {
        $rows = [
            $this->row(h: '昭47. 3. 1'),
        ];

        $result = (new DesignationHistoryParser)->parse($rows);

        $this->assertSame('1972-03-01', $result['designatedOn']);
        $this->assertSame([], $result['history']);
    }

    public function test_a_reason_and_date_pair_becomes_one_history_entry(): void
    {
        $rows = [
            $this->row(h: '昭47. 3. 1'),
            $this->row(h: '新規'),
            $this->row(h: '令5. 3. 1'),
        ];

        $result = (new DesignationHistoryParser)->parse($rows);

        $this->assertSame('1972-03-01', $result['designatedOn']);
        $this->assertSame([
            ['reason' => '新規', 'date' => '2023-03-01'],
        ], $result['history']);
    }

    public function test_a_date_without_a_reason_becomes_an_entry_with_a_null_reason(): void
    {
        // About 10% of real records (every bureau) carry no reason label,
        // only the designation date and one more date.
        $rows = [
            $this->row(h: '昭32. 11. 1'),
            $this->row(h: '令5. 11. 1'),
        ];

        $result = (new DesignationHistoryParser)->parse($rows);

        $this->assertSame('1957-11-01', $result['designatedOn']);
        $this->assertSame([
            ['reason' => null, 'date' => '2023-11-01'],
        ], $result['history']);
    }

    public function test_a_reason_without_a_date_becomes_an_entry_with_a_null_date(): void
    {
        $rows = [
            $this->row(h: '昭47. 3. 1'),
            $this->row(h: '新規'),
        ];

        $result = (new DesignationHistoryParser)->parse($rows);

        $this->assertSame([
            ['reason' => '新規', 'date' => null],
        ], $result['history']);
    }

    public function test_entries_with_and_without_reasons_are_told_apart_by_value(): void
    {
        $rows = [
            $this->row(h: '昭47. 3. 1'),
            $this->row(h: '令2. 3. 1'),
            $this->row(h: '交代'),
            $this->row(h: '令5. 3. 1'),
        ];

        $result = (new DesignationHistoryParser)->parse($rows);

        $this->assertSame([
            ['reason' => null, 'date' => '2020-03-01'],
            ['reason' => '交代', 'date' => '2023-03-01'],
        ], $result['history']);
    }

    public function test_no_column_h_values_at_all_returns_null_designated_on(): void
    {
        $rows = [
            $this->row(h: ''),
        ];

        $result = (new DesignationHistoryParser)->parse($rows);

        $this->assertNull($result['designatedOn']);
        $this->assertSame([], $result['history']);
    }

    /**
     * @return array<int, string>
     */
    private function row(string $h): array
    {
        $row = array_fill(0, 10, '');
        $row[7] = $h;

        return $row;
    }
}
