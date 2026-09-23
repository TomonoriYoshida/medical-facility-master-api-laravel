<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Download\ChugokuShikokuBundleExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsXlsxFixture;
use Tests\TestCase;

class ChugokuShikokuBundleExpanderTest extends TestCase
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
    private function seedDownload(array $entries, string $filename = '000500018.zip'): RhbDatasetDownload
    {
        $zipPath = $this->createZipOfXlsxFiles($entries);
        $localPath = "rhb/chugokushikoku/medical/{$filename}";

        Storage::disk('local')->put($localPath, file_get_contents($zipPath));

        return RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::ChugokuShikoku,
            'category' => RhbCategory::Medical,
            'local_path' => $localPath,
        ]);
    }

    public function test_it_expands_one_unit_per_xlsx_entry_mapped_to_the_correct_prefecture_code(): void
    {
        $download = $this->seedDownload([
            '医科_31_鳥取_コード内容別医療機関一覧表.xlsx' => [['1', 'tottori-facility']],
            '医科_34_広島_コード内容別医療機関一覧表.xlsx' => [['1', 'hiroshima-facility']],
        ]);

        $units = (new ChugokuShikokuBundleExpander)->expand($download);

        $this->assertCount(2, $units);
        $this->assertSame('31', $units[0]->prefectureCode);
        $this->assertSame('34', $units[1]->prefectureCode);
        $this->assertSame(RhbBureau::ChugokuShikoku, $units[0]->bureau);
        $this->assertSame(RhbCategory::Medical, $units[0]->category);
        $this->assertNull($units[0]->sheetName);
    }

    public function test_an_unrecognized_prefecture_name_throws_rather_than_being_silently_skipped(): void
    {
        $download = $this->seedDownload([
            '医科_99_謎県_コード内容別医療機関一覧表.xlsx' => [['1', 'unknown']],
        ]);

        $this->expectException(RuntimeException::class);

        (new ChugokuShikokuBundleExpander)->expand($download);
    }
}
