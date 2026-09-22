<?php

namespace Tests\Unit\Services\Mhlw\Import;

use App\Enums\InstitutionType;
use App\Services\Mhlw\Import\MaternityHomeRowMapper;
use PHPUnit\Framework\TestCase;

class MaternityHomeRowMapperTest extends TestCase
{
    public function test_working_hours_and_reception_hours_are_kept_as_two_distinct_categories(): void
    {
        $row = array_fill(0, 153, '');
        $row[0] = '0000000000004';
        $row[1] = '助産院テスト';
        $row[7] = '01';
        $row[8] = '101';
        $row[9] = '所在地';
        // 就業時間帯1, mon (index 57-58)
        $row[57] = '09:00';
        $row[58] = '15:00';
        // 外来受付時間帯1, mon (index 105-106) -- deliberately different values
        $row[105] = '10:00';
        $row[106] = '16:00';

        $mapped = (new MaternityHomeRowMapper)->map($row);

        $this->assertSame(InstitutionType::MaternityHome, $mapped['institution_type']);
        $this->assertSame(
            [['start' => '09:00', 'end' => '15:00']],
            $mapped['business_hours']['mon'],
        );
        $this->assertSame(
            [['start' => '10:00', 'end' => '16:00']],
            $mapped['reception_hours']['mon'],
        );
        $this->assertSame([], $mapped['business_hours']['tue']);
        $this->assertNull($mapped['general_beds']);
        $this->assertNull($mapped['total_beds']);
    }
}
