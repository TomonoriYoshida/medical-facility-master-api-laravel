<?php

namespace Tests\Unit\Services\Mhlw\Import;

use App\Services\Mhlw\Import\MhlwCsvReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class MhlwCsvReaderTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempZipPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempZipPaths as $path) {
            @unlink($path);
            @rmdir(dirname($path));
        }

        $this->tempZipPaths = [];

        parent::tearDown();
    }

    public function test_it_strips_the_bom_skips_the_header_and_yields_data_rows(): void
    {
        $csv = "\xEF\xBB\xBFID,name\r\n0001,テスト\r\n0002,サンプル\r\n";
        $zipPath = $this->createZip('data.csv', $csv);

        $rows = iterator_to_array((new MhlwCsvReader($zipPath, 'data.csv'))->rows());

        $this->assertSame([['0001', 'テスト'], ['0002', 'サンプル']], $rows);
    }

    public function test_it_works_without_a_bom_too(): void
    {
        $csv = "ID,name\r\n0001,テスト\r\n";
        $zipPath = $this->createZip('data.csv', $csv);

        $rows = iterator_to_array((new MhlwCsvReader($zipPath, 'data.csv'))->rows());

        $this->assertSame([['0001', 'テスト']], $rows);
    }

    public function test_it_skips_fully_blank_rows(): void
    {
        $csv = "ID,name\r\n0001,テスト\r\n\r\n0002,サンプル\r\n";
        $zipPath = $this->createZip('data.csv', $csv);

        $rows = iterator_to_array((new MhlwCsvReader($zipPath, 'data.csv'))->rows());

        $this->assertSame([['0001', 'テスト'], ['0002', 'サンプル']], $rows);
    }

    public function test_it_infers_the_entry_name_from_the_zip_filename_by_default(): void
    {
        $csv = "ID,name\r\n0001,テスト\r\n";
        $zipPath = $this->createZip('01-1_hospital_facility_info_20260601.csv', $csv, zipBasename: '01-1_hospital_facility_info_20260601.csv.zip');

        $rows = iterator_to_array((new MhlwCsvReader($zipPath))->rows());

        $this->assertSame([['0001', 'テスト']], $rows);
    }

    private function createZip(string $entryName, string $contents, ?string $zipBasename = null): string
    {
        if ($zipBasename !== null) {
            // Needs its exact real filename (not a tempnam-randomized one)
            // so MhlwCsvReader's basename-minus-".zip" inference can be
            // tested -- an isolated per-test directory avoids collisions.
            $directory = sys_get_temp_dir().'/'.uniqid('mhlw_csv_reader_test_', more_entropy: true);
            mkdir($directory);
            $zipPath = $directory.'/'.$zipBasename;
        } else {
            $zipPath = tempnam(sys_get_temp_dir(), 'mhlw_csv_reader_test_').'.zip';
        }

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($entryName, $contents);
        $zip->close();

        $this->tempZipPaths[] = $zipPath;

        return $zipPath;
    }
}
