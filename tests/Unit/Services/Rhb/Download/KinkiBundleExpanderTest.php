<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Download\KinkiBundleExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsXlsxFixture;
use Tests\TestCase;

class KinkiBundleExpanderTest extends TestCase
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
    private function seedDownload(array $entries, string $filename = '2026.9_kikanzentai_ika.zip'): RhbDatasetDownload
    {
        $zipPath = $this->createZipOfXlsxFiles($entries);
        $localPath = "rhb/kinki/medical/{$filename}";

        Storage::disk('local')->put($localPath, file_get_contents($zipPath));

        return RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::Kinki,
            'category' => RhbCategory::Medical,
            'local_path' => $localPath,
        ]);
    }

    public function test_it_expands_one_unit_per_xlsx_entry_mapped_to_the_correct_prefecture_code(): void
    {
        $download = $this->seedDownload([
            '2026.9_kikanzentai_ika/2026.9_kikanzentai_fukui_ika.xlsx' => [['1', 'fukui-facility']],
            '2026.9_kikanzentai_ika/2026.9_kikanzentai_osaka_ika.xlsx' => [['1', 'osaka-facility']],
        ]);

        $units = (new KinkiBundleExpander)->expand($download);

        $this->assertCount(2, $units);
        $this->assertSame('18', $units[0]->prefectureCode);
        $this->assertSame('27', $units[1]->prefectureCode);
        $this->assertSame(RhbBureau::Kinki, $units[0]->bureau);
        $this->assertSame(RhbCategory::Medical, $units[0]->category);
        $this->assertNull($units[0]->sheetName);
    }

    public function test_heisetsu_subset_entries_mixed_into_the_zip_are_excluded(): void
    {
        // Regression test: unlike Kanto-Shinetsu/Tokai-Hokuriku, Kinki
        // bundles 併設 (co-located) subset files into the SAME zip as the
        // plain files (e.g. "..._hyogo_ika.xlsx" alongside
        // "..._hyogo_ikaheisetu.xlsx") rather than as separate download
        // links, so they must be filtered here instead of at the
        // LinkResolver stage. Note the romanization drops the "s":
        // "heisetu", not "heisetsu".
        $download = $this->seedDownload([
            '2026.9_kikanzentai_ika/2026.9_kikanzentai_hyogo_ika.xlsx' => [['1', 'hyogo-facility']],
            '2026.9_kikanzentai_ika/2026.9_kikanzentai_hyogo_ikaheisetu.xlsx' => [['1', 'hyogo-heisetsu-facility']],
        ]);

        $units = (new KinkiBundleExpander)->expand($download);

        $this->assertCount(1, $units);
        $this->assertSame('28', $units[0]->prefectureCode);
        $this->assertStringNotContainsString('heisetu', $units[0]->xlsxPath);
    }

    public function test_an_unrecognized_prefecture_name_throws_rather_than_being_silently_skipped(): void
    {
        $download = $this->seedDownload([
            '2026.9_kikanzentai_ika/2026.9_kikanzentai_nazo_ika.xlsx' => [['1', 'unknown']],
        ]);

        $this->expectException(RuntimeException::class);

        (new KinkiBundleExpander)->expand($download);
    }
}
