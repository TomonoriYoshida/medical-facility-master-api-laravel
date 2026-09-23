<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Services\Rhb\Import\RecordGrouper;
use PHPUnit\Framework\TestCase;

class RecordGrouperTest extends TestCase
{
    public function test_it_skips_title_block_rows_before_the_first_record(): void
    {
        $rows = [
            ['コード内容別医療機関一覧表'],
            ['[令和 8年 9月 1日現在　医科　現存/休止]'],
            ['1', 'A'],
        ];

        $records = iterator_to_array((new RecordGrouper)->group($rows));

        $this->assertCount(1, $records);
        $this->assertSame(1, $records[0]['serial']);
    }

    public function test_it_groups_a_variable_number_of_continuation_rows_into_one_record(): void
    {
        $rows = [
            ['1', '医院A'],
            ['', '常勤:1'],
            ['', '(医 1)'],
            ['2', '医院B'],
        ];

        $records = iterator_to_array((new RecordGrouper)->group($rows));

        $this->assertCount(2, $records);
        $this->assertSame(1, $records[0]['serial']);
        $this->assertCount(3, $records[0]['rows']);
        $this->assertSame(2, $records[1]['serial']);
        $this->assertCount(1, $records[1]['rows']);
    }

    public function test_a_blank_column_a_never_starts_a_new_record(): void
    {
        $rows = [
            ['1', '医院A'],
            ['', 'continuation'],
        ];

        $records = iterator_to_array((new RecordGrouper)->group($rows));

        $this->assertCount(1, $records);
        $this->assertCount(2, $records[0]['rows']);
    }

    public function test_the_last_record_is_flushed_at_end_of_stream(): void
    {
        $rows = [
            ['1', '医院A'],
        ];

        $records = iterator_to_array((new RecordGrouper)->group($rows));

        $this->assertCount(1, $records);
    }

    public function test_no_records_at_all_yields_nothing(): void
    {
        $rows = [
            ['コード内容別医療機関一覧表'],
        ];

        $records = iterator_to_array((new RecordGrouper)->group($rows));

        $this->assertSame([], $records);
    }
}
