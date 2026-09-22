<?php

namespace Tests\Unit\Services\Mhlw\Import;

use App\Services\Mhlw\Import\SpecialityDatasetRowSource;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class SpecialityDatasetRowSourceTest extends TestCase
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

    public function test_it_groups_and_maps_rows_end_to_end_from_a_zip(): void
    {
        $band1 = $this->row('0001', '01001', '内科', '1');
        $band1[4] = '09:00';
        $band1[5] = '12:00';

        $band2 = $this->row('0001', '02001', '外科', '1');
        $band2[4] = '10:00';
        $band2[5] = '13:00';

        $zipPath = $this->createZip([$band1, $band2]);

        $mapped = iterator_to_array((new SpecialityDatasetRowSource)->rows($zipPath));

        $this->assertCount(2, $mapped);
        $this->assertSame('01001', $mapped[0]['department_code']);
        $this->assertSame('内科', $mapped[0]['department_name']);
        $this->assertSame([['start' => '09:00', 'end' => '12:00']], $mapped[0]['consultation_hours']['mon']);
        $this->assertSame('02001', $mapped[1]['department_code']);
        $this->assertSame('外科', $mapped[1]['department_name']);
    }

    /**
     * @return array<int, string>
     */
    private function row(string $id, string $departmentCode, string $departmentName, string $band): array
    {
        $row = array_fill(0, 36, '');
        $row[0] = $id;
        $row[1] = $departmentCode;
        $row[2] = $departmentName;
        $row[3] = $band;

        return $row;
    }

    /**
     * @param  list<array<int, string>>  $rows
     */
    private function createZip(array $rows): string
    {
        $csv = "header\r\n";

        foreach ($rows as $row) {
            $csv .= implode(',', $row)."\r\n";
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'speciality_dataset_row_source_test_').'.zip';
        $entryName = basename($zipPath, '.zip');

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($entryName, $csv);
        $zip->close();

        $this->tempZipPaths[] = $zipPath;

        return $zipPath;
    }
}
