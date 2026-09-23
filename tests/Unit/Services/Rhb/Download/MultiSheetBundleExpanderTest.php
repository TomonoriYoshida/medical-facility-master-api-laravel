<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Download\MultiSheetBundleExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsXlsxFixture;
use Tests\TestCase;

class MultiSheetBundleExpanderTest extends TestCase
{
    use BuildsXlsxFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->cleanUpXlsxFixtures();

        parent::tearDown();
    }

    /**
     * @param  array<string, list<list<string>>>  $sheets
     */
    private function seedDownload(array $sheets, string $filename = 'test.xlsx'): RhbDatasetDownload
    {
        $tempPath = $this->createMultiSheetXlsx($sheets);
        $localPath = "rhb/tohoku/medical/{$filename}";

        Storage::disk('local')->put($localPath, file_get_contents($tempPath));

        return RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::Tohoku,
            'category' => RhbCategory::Medical,
            'local_path' => $localPath,
        ]);
    }

    public function test_it_expands_one_unit_per_sheet_mapped_to_the_correct_prefecture_code(): void
    {
        $download = $this->seedDownload([
            '青森県' => [['1', 'aomori-facility']],
            '岩手県' => [['1', 'iwate-facility']],
        ]);

        $units = (new MultiSheetBundleExpander)->expand($download);

        $this->assertCount(2, $units);
        $this->assertSame('02', $units[0]->prefectureCode);
        $this->assertSame('青森県', $units[0]->sheetName);
        $this->assertSame('03', $units[1]->prefectureCode);
        $this->assertSame('岩手県', $units[1]->sheetName);
        $this->assertSame(RhbBureau::Tohoku, $units[0]->bureau);
        $this->assertSame(RhbCategory::Medical, $units[0]->category);
    }

    public function test_an_unrecognized_sheet_name_throws_rather_than_being_silently_skipped(): void
    {
        $download = $this->seedDownload([
            '謎の県' => [['1', 'unknown']],
        ]);

        $this->expectException(RuntimeException::class);

        (new MultiSheetBundleExpander)->expand($download);
    }
}
