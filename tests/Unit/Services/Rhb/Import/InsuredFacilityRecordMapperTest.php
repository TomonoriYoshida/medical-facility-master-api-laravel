<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Services\Rhb\Import\InsuredFacilityRecordMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class InsuredFacilityRecordMapperTest extends TestCase
{
    public function test_it_maps_a_real_shaped_hospital_record(): void
    {
        $record = [
            'serial' => 1,
            'rows' => [
                $this->row([
                    0 => '1',
                    1 => '01,1248,9',
                    2 => '医療法人　愛全病院',
                    3 => '〒005－0813札幌市南区川沿１３条２丁目１番３８号',
                    4 => '011-571-5670',
                    5 => '医療法人　愛全会',
                    6 => '松原　泉',
                    7 => '昭47. 3. 1',
                    8 => "療養\u{3000}\u{3000} 206",
                    9 => '病院',
                ]),
                $this->row([4 => "常\u{3000}勤:\u{3000}\u{3000}\u{3000}19", 7 => '新規', 8 => "一般\u{3000}\u{3000} 231", 9 => '現存']),
                $this->row([7 => '令5. 3. 1', 8 => '内　消化器内科']),
            ],
        ];

        $mapped = (new InsuredFacilityRecordMapper)->map($record, RhbCategory::Medical, RhbBureau::Hokkaido, '01');

        $this->assertSame('0112489', $mapped['facility_code']);
        $this->assertSame(RhbBureau::Hokkaido, $mapped['bureau_code']);
        $this->assertSame(InstitutionType::Hospital, $mapped['institution_type']);
        $this->assertSame(MedicalFacilityStatus::Active, $mapped['status']);
        $this->assertSame('医療法人　愛全病院', $mapped['name']);
        $this->assertSame('01', $mapped['prefecture_code']);
        $this->assertSame('005-0813', $mapped['postal_code']);
        $this->assertSame('札幌市南区川沿１３条２丁目１番３８号', $mapped['address']);
        $this->assertSame('011-571-5670', $mapped['phone_number']);
        $this->assertSame('医療法人　愛全会', $mapped['founder_name']);
        $this->assertSame('松原　泉', $mapped['administrator_name']);
        $this->assertSame('1972-03-01', $mapped['designated_on']);
        $this->assertSame([['reason' => '新規', 'date' => '2023-03-01']], $mapped['designation_history']);
        $this->assertSame(['療養' => 206, '一般' => 231], $mapped['bed_counts']);
        $this->assertSame([DepartmentBaseCategory::InternalMedicine, DepartmentBaseCategory::Gastroenterology], $mapped['department_categories']);
    }

    public function test_a_clinic_within_the_medical_category_is_distinguished_from_a_hospital(): void
    {
        $record = [
            'serial' => 1,
            'rows' => [
                $this->row([
                    0 => '1', 1 => '01,1000,0', 2 => 'テストクリニック',
                    3 => '〒000－0000テスト住所', 4 => '011-000-0000',
                    5 => '院長', 6 => '院長', 7 => '昭50. 1. 1', 9 => '診療所',
                ]),
            ],
        ];

        $mapped = (new InsuredFacilityRecordMapper)->map($record, RhbCategory::Medical, RhbBureau::Hokkaido, '01');

        $this->assertSame(InstitutionType::Clinic, $mapped['institution_type']);
    }

    public function test_the_same_shitei_sho_label_resolves_to_dental_clinic_under_the_dental_category(): void
    {
        $record = [
            'serial' => 1,
            'rows' => [
                $this->row([
                    0 => '1', 1 => '01,3000,0', 2 => 'テスト歯科',
                    3 => '〒000－0000テスト住所', 4 => '011-000-0000',
                    5 => '院長', 6 => '院長', 7 => '昭50. 1. 1', 9 => '診療所',
                ]),
            ],
        ];

        $mapped = (new InsuredFacilityRecordMapper)->map($record, RhbCategory::Dental, RhbBureau::Hokkaido, '01');

        $this->assertSame(InstitutionType::DentalClinic, $mapped['institution_type']);
    }

    public function test_pharmacy_category_never_reads_column_i_even_if_present(): void
    {
        $record = [
            'serial' => 1,
            'rows' => [
                $this->row([
                    0 => '1', 1 => '01,4093,0', 2 => 'テスト薬局',
                    3 => '〒000－0000テスト住所', 4 => '011-000-0000',
                    5 => '薬局代表', 6 => '薬局代表', 7 => '昭32. 10. 23',
                    8 => 'should-be-ignored', 9 => '薬局',
                ]),
            ],
        ];

        $mapped = (new InsuredFacilityRecordMapper)->map($record, RhbCategory::Pharmacy, RhbBureau::Hokkaido, '01');

        $this->assertSame(InstitutionType::Pharmacy, $mapped['institution_type']);
        $this->assertNull($mapped['bed_counts']);
        $this->assertSame([], $mapped['department_categories']);
    }

    public function test_an_unexpected_type_label_under_the_medical_category_throws(): void
    {
        $record = [
            'serial' => 1,
            'rows' => [
                $this->row([
                    0 => '1', 1 => '01,1000,0', 2 => 'テスト',
                    3 => '〒000－0000テスト住所', 4 => '011-000-0000',
                    5 => '代表', 6 => '代表', 7 => '昭50. 1. 1', 9 => '不明区分',
                ]),
            ],
        ];

        $this->expectException(InvalidArgumentException::class);

        (new InsuredFacilityRecordMapper)->map($record, RhbCategory::Medical, RhbBureau::Hokkaido, '01');
    }

    /**
     * @param  array<int, string>  $overrides
     * @return array<int, string>
     */
    private function row(array $overrides): array
    {
        $row = array_fill(0, 10, '');

        foreach ($overrides as $index => $value) {
            $row[$index] = $value;
        }

        return $row;
    }
}
