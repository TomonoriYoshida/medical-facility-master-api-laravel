<?php

namespace Tests\Unit\Services\Mhlw\Import;

use App\Enums\InstitutionType;
use App\Services\Mhlw\Import\FacilityDatasetRowSource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class FacilityDatasetRowSourceTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempZipPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempZipPaths as $path) {
            @unlink($path);
        }

        $this->tempZipPaths = [];

        parent::tearDown();
    }

    public function test_hospital_facility_routes_to_the_hospital_layout(): void
    {
        $row = $this->buildRow(65, [0 => '0001', 1 => '病院', 7 => '01', 8 => '101', 9 => '住所', 57 => '10']);
        $zipPath = $this->createZip($row);

        $mapped = iterator_to_array((new FacilityDatasetRowSource)->rows('hospital_facility', $zipPath));

        $this->assertSame(InstitutionType::Hospital, $mapped[0]['institution_type']);
        $this->assertSame(10, $mapped[0]['general_beds']);
    }

    public function test_clinic_facility_routes_to_the_clinic_layout(): void
    {
        $row = $this->buildRow(62, [0 => '0002', 1 => 'クリニック', 7 => '01', 8 => '101', 9 => '住所', 61 => '5']);
        $zipPath = $this->createZip($row);

        $mapped = iterator_to_array((new FacilityDatasetRowSource)->rows('clinic_facility', $zipPath));

        $this->assertSame(InstitutionType::Clinic, $mapped[0]['institution_type']);
        $this->assertSame(5, $mapped[0]['total_beds']);
    }

    public function test_dental_facility_routes_to_the_dental_layout_with_no_bed_columns(): void
    {
        $row = $this->buildRow(13, [0 => '0003', 1 => '歯科', 7 => '01', 8 => '101', 9 => '住所']);
        $zipPath = $this->createZip($row);

        $mapped = iterator_to_array((new FacilityDatasetRowSource)->rows('dental_facility', $zipPath));

        $this->assertSame(InstitutionType::DentalClinic, $mapped[0]['institution_type']);
        $this->assertNull($mapped[0]['total_beds']);
    }

    public function test_maternity_home_routes_to_the_maternity_home_mapper(): void
    {
        $row = $this->buildRow(13, [0 => '0004', 1 => '助産所', 7 => '01', 8 => '101', 9 => '住所']);
        $zipPath = $this->createZip($row);

        $mapped = iterator_to_array((new FacilityDatasetRowSource)->rows('maternity_home', $zipPath));

        $this->assertSame(InstitutionType::MaternityHome, $mapped[0]['institution_type']);
    }

    public function test_pharmacy_routes_to_the_pharmacy_mapper(): void
    {
        $row = $this->buildRow(11, [0 => '0005', 1 => '薬局', 5 => '01', 6 => '101', 7 => '住所']);
        $zipPath = $this->createZip($row);

        $mapped = iterator_to_array((new FacilityDatasetRowSource)->rows('pharmacy', $zipPath));

        $this->assertSame(InstitutionType::Pharmacy, $mapped[0]['institution_type']);
    }

    public function test_an_unknown_dataset_key_throws(): void
    {
        $row = $this->buildRow(13, [0 => '0006', 1 => '不明', 7 => '01', 8 => '101', 9 => '住所']);
        $zipPath = $this->createZip($row);

        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new FacilityDatasetRowSource)->rows('unknown_dataset', $zipPath));
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

    /**
     * @param  array<int, string>  $row
     */
    private function createZip(array $row): string
    {
        $csv = "header\r\n".implode(',', $row)."\r\n";

        $zipPath = tempnam(sys_get_temp_dir(), 'facility_dataset_row_source_test_').'.zip';
        $entryName = basename($zipPath, '.zip');

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($entryName, $csv);
        $zip->close();

        $this->tempZipPaths[] = $zipPath;

        return $zipPath;
    }
}
