<?php

namespace Tests\Unit\Services\Mhlw\Import;

use App\Enums\InstitutionType;
use App\Services\Mhlw\Import\PharmacyRowMapper;
use PHPUnit\Framework\TestCase;

class PharmacyRowMapperTest extends TestCase
{
    public function test_it_maps_the_eleven_column_core_and_merges_multiple_opening_bands(): void
    {
        $row = array_fill(0, 128, '');
        $row[0] = '0000000000005';
        $row[1] = 'アイリス調剤薬局';
        $row[2] = 'アイリスチョウザイヤッキョク';
        $row[3] = 'Iris Pharmacy';
        $row[5] = '01';
        $row[6] = '101';
        $row[7] = '所在地';
        // 開店時間帯1, mon (index 64-65)
        $row[64] = '08:30';
        $row[65] = '12:30';
        // 開店時間帯2, mon (band size = 16 cols, so band 2 starts at 64+16=80)
        $row[80] = '12:30';
        $row[81] = '17:30';

        $mapped = (new PharmacyRowMapper)->map($row);

        $this->assertSame(InstitutionType::Pharmacy, $mapped['institution_type']);
        $this->assertSame('アイリス調剤薬局', $mapped['name']);
        $this->assertNull($mapped['short_name']);
        $this->assertNull($mapped['short_name_kana']);
        $this->assertSame(
            [
                ['start' => '08:30', 'end' => '12:30'],
                ['start' => '12:30', 'end' => '17:30'],
            ],
            $mapped['business_hours']['mon'],
        );
        $this->assertNull($mapped['reception_hours']);
        $this->assertArrayHasKey('open_weekdays', $mapped['closure_schedule']);
        $this->assertArrayHasKey('weekly_holiday_flag', $mapped['closure_schedule']);
        $this->assertNull($mapped['general_beds']);
    }
}
