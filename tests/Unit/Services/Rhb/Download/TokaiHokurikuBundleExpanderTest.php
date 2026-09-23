<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Download\TokaiHokurikuBundleExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsXlsxFixture;
use Tests\TestCase;

class TokaiHokurikuBundleExpanderTest extends TestCase
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
     * @param  array<string, list<list<string>>>  $entries
     */
    private function seedDownload(array $entries, string $filename = '2609-01-01.zip'): RhbDatasetDownload
    {
        $zipPath = $this->createZipOfXlsxFiles($entries);
        $localPath = "rhb/tokaihokuriku/medical/{$filename}";

        Storage::disk('local')->put($localPath, file_get_contents($zipPath));

        return RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::TokaiHokuriku,
            'category' => RhbCategory::Medical,
            'local_path' => $localPath,
        ]);
    }

    public function test_it_expands_one_unit_per_xlsx_entry_mapped_to_the_correct_prefecture_code(): void
    {
        $download = $this->seedDownload([
            '2609（富山医科）コード内容別医療機関一覧表.xlsx' => [['1', 'toyama-facility']],
            '2609（三重医科）コード内容別医療機関一覧表.xlsx' => [['1', 'mie-facility']],
        ]);

        $units = (new TokaiHokurikuBundleExpander)->expand($download);

        $this->assertCount(2, $units);
        $this->assertSame('16', $units[0]->prefectureCode);
        $this->assertSame('24', $units[1]->prefectureCode);
        $this->assertSame(RhbBureau::TokaiHokuriku, $units[0]->bureau);
        $this->assertSame(RhbCategory::Medical, $units[0]->category);
        $this->assertNull($units[0]->sheetName);
    }

    public function test_a_backslash_nested_folder_entry_name_does_not_leak_into_the_stored_filename(): void
    {
        // Regression test: the real zip nests each xlsx under a
        // backslash-separated folder entry (Windows-zip origin), and
        // PHP's basename() only splits on "/" on Linux -- without
        // normalizing first, the folder prefix would leak into the
        // stored filename instead of being stripped.
        $download = $this->seedDownload([
            '2609　コード内容別医療機関一覧表（医科）\2609（富山医科）コード内容別医療機関一覧表.xlsx' => [['1', 'toyama-facility']],
        ]);

        $units = (new TokaiHokurikuBundleExpander)->expand($download);

        $this->assertCount(1, $units);
        $this->assertSame('16', $units[0]->prefectureCode);
        $this->assertStringNotContainsString('\\', basename($units[0]->xlsxPath));
    }

    public function test_an_unrecognized_prefecture_name_throws_rather_than_being_silently_skipped(): void
    {
        $download = $this->seedDownload([
            '2609（謎県医科）コード内容別医療機関一覧表.xlsx' => [['1', 'unknown']],
        ]);

        $this->expectException(RuntimeException::class);

        (new TokaiHokurikuBundleExpander)->expand($download);
    }
}
