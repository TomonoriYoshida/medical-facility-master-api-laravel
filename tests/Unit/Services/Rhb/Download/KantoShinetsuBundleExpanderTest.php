<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Download\KantoShinetsuBundleExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsXlsxFixture;
use Tests\TestCase;

class KantoShinetsuBundleExpanderTest extends TestCase
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
    private function seedDownload(array $entries, string $filename = 'shitei_ika_r0809.zip'): RhbDatasetDownload
    {
        $zipPath = $this->createZipOfXlsxFiles($entries);
        $localPath = "rhb/kantoshinetsu/medical/{$filename}";

        Storage::disk('local')->put($localPath, file_get_contents($zipPath));

        return RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::KantoShinetsu,
            'category' => RhbCategory::Medical,
            'local_path' => $localPath,
        ]);
    }

    public function test_it_expands_one_unit_per_xlsx_entry_mapped_to_the_correct_prefecture_code(): void
    {
        $download = $this->seedDownload([
            '081コード内容別一覧表（医科）茨城r0809.xlsx' => [['1', 'ibaraki-facility']],
            '111コード内容別一覧表（医科）埼玉r0809.xlsx' => [['1', 'saitama-facility']],
        ]);

        $units = (new KantoShinetsuBundleExpander)->expand($download);

        $this->assertCount(2, $units);
        $this->assertSame('08', $units[0]->prefectureCode);
        $this->assertSame('11', $units[1]->prefectureCode);
        $this->assertSame(RhbBureau::KantoShinetsu, $units[0]->bureau);
        $this->assertSame(RhbCategory::Medical, $units[0]->category);
        $this->assertNull($units[0]->sheetName);
    }

    public function test_a_non_xlsx_entry_in_the_zip_is_skipped(): void
    {
        $download = $this->seedDownload([
            '081コード内容別一覧表（医科）茨城r0809.xlsx' => [['1', 'ibaraki-facility']],
        ]);

        $units = (new KantoShinetsuBundleExpander)->expand($download);

        $this->assertCount(1, $units);
    }

    public function test_an_unrecognized_prefecture_name_throws_rather_than_being_silently_skipped(): void
    {
        $download = $this->seedDownload([
            '999コード内容別一覧表（医科）謎県r0809.xlsx' => [['1', 'unknown']],
        ]);

        $this->expectException(RuntimeException::class);

        (new KantoShinetsuBundleExpander)->expand($download);
    }
}
