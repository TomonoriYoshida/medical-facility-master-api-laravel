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
