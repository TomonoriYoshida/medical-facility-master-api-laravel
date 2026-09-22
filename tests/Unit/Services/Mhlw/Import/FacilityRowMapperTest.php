<?php

namespace Tests\Unit\Services\Mhlw\Import;

use App\Enums\InstitutionType;
use App\Services\Mhlw\Import\FacilityColumnLayout;
use App\Services\Mhlw\Import\FacilityRowMapper;
use PHPUnit\Framework\TestCase;

class FacilityRowMapperTest extends TestCase
{
    public function test_it_maps_core_fields_and_all_eight_hospital_bed_columns(): void
    {
        $row = $this->buildRow(65, [
            0 => '0111010000010',
            1 => '札幌医科大学附属病院',
            2 => 'サッポロイカダイガクフゾクビョウイン',
            3 => '札医大',
            4 => 'サツイダイ',
            5 => 'Sapporo Medical University Hospital',
            7 => '01',
            8 => '101',
            9 => '北海道札幌市中央区南１条西１６丁目２９１番地',
            10 => '43.055405',
            11 => '141.333497',
            12 => 'https://web.sapmed.ac.jp/hospital/',
            57 => '812', 58 => '', 59 => '', 60 => '',
            61 => '32', 62 => '', 63 => '', 64 => '844',
        ]);

        $mapped = (new FacilityRowMapper)->map($row, FacilityColumnLayout::hospital());

        $this->assertSame('0111010000010', $mapped['source_id']);
        $this->assertSame(InstitutionType::Hospital, $mapped['institution_type']);
        $this->assertSame('札幌医科大学附属病院', $mapped['name']);
        $this->assertSame(43.055405, $mapped['latitude']);
        $this->assertSame(141.333497, $mapped['longitude']);
        $this->assertSame(812, $mapped['general_beds']);
        $this->assertSame(32, $mapped['psychiatric_beds']);
        $this->assertSame(844, $mapped['total_beds']);
        $this->assertNull($mapped['sanatorium_beds']);
        $this->assertNull($mapped['business_hours']);
        $this->assertNull($mapped['reception_hours']);
    }

    public function test_clinic_bed_columns_skip_straight_to_total_beds(): void
    {
        $row = $this->buildRow(62, [
            0 => '0000000000001',
            1 => 'テストクリニック',
            7 => '01',
            8 => '101',
            9 => '所在地',
            57 => '19', 58 => '', 59 => '', 60 => '', 61 => '19',
        ]);

        $mapped = (new FacilityRowMapper)->map($row, FacilityColumnLayout::clinic());

        $this->assertSame(InstitutionType::Clinic, $mapped['institution_type']);
        $this->assertSame(19, $mapped['general_beds']);
        $this->assertSame(19, $mapped['total_beds']);
        $this->assertNull($mapped['psychiatric_beds']);
        $this->assertNull($mapped['tuberculosis_beds']);
        $this->assertNull($mapped['infectious_disease_beds']);
    }

    public function test_dental_has_no_bed_columns_at_all(): void
    {
        $row = $this->buildRow(57, [
            0 => '0000000000002',
            1 => 'テスト歯科',
            7 => '01',
            8 => '101',
            9 => '所在地',
        ]);

        $mapped = (new FacilityRowMapper)->map($row, FacilityColumnLayout::dental());

        $this->assertSame(InstitutionType::DentalClinic, $mapped['institution_type']);
        foreach (['general_beds', 'sanatorium_beds', 'sanatorium_beds_medical_insurance', 'sanatorium_beds_care_insurance', 'psychiatric_beds', 'tuberculosis_beds', 'infectious_disease_beds', 'total_beds'] as $key) {
            $this->assertNull($mapped[$key], "{$key} should be null");
        }
    }

    public function test_blank_optional_columns_become_null(): void
    {
        $row = $this->buildRow(57, [
            0 => '0000000000003',
            1 => '名称のみ',
            7 => '01',
            8 => '101',
            9 => '所在地',
        ]);

        $mapped = (new FacilityRowMapper)->map($row, FacilityColumnLayout::dental());

        $this->assertNull($mapped['name_kana']);
        $this->assertNull($mapped['short_name']);
        $this->assertNull($mapped['short_name_kana']);
        $this->assertNull($mapped['name_en']);
        $this->assertNull($mapped['latitude']);
        $this->assertNull($mapped['longitude']);
        $this->assertNull($mapped['website_url']);
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
