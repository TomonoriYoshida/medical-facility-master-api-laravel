<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Download\KyushuBundleExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsXlsxFixture;
use Tests\TestCase;

class KyushuBundleExpanderTest extends TestCase
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
    private function seedDownload(RhbCategory $category, array $entries, string $filename = '000500310.zip'): RhbDatasetDownload
    {
        $zipPath = $this->createZipOfXlsxFiles($entries);
        $localPath = "rhb/kyushu/{$category->name}/{$filename}";

        Storage::disk('local')->put($localPath, file_get_contents($zipPath));

        return RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::Kyushu,
            'category' => $category,
            'local_path' => $localPath,
        ]);
    }

    /**
     * @param  array<string, list<list<string>>>  $entries
     */
    private function allThreeCategoryEntries(): array
    {
        return [
            'r8_09_ika_fukuoka_02.xlsx' => [['1', 'medical-facility']],
            'r8_09_shika_fukuoka_02.xlsx' => [['1', 'dental-facility']],
            'r8_09_yakkyoku_fukuoka_02.xlsx' => [['1', 'pharmacy-facility']],
        ];
    }

    public function test_it_extracts_only_the_entry_matching_the_downloads_own_category(): void
    {
        // Regression test: unlike every other bureau, one zip bundles all
        // 3 categories for one prefecture -- a Dental-tagged download must
        // extract only the shika entry, ignoring the ika/yakkyoku entries
        // in the same zip.
        $download = $this->seedDownload(RhbCategory::Dental, $this->allThreeCategoryEntries());

        $units = (new KyushuBundleExpander)->expand($download);

        $this->assertCount(1, $units);
        $this->assertSame('40', $units[0]->prefectureCode);
        $this->assertSame(RhbCategory::Dental, $units[0]->category);
        $this->assertStringContainsString('shika', $units[0]->xlsxPath);
    }

    public function test_it_resolves_the_prefecture_regardless_of_whether_the_category_or_prefecture_name_comes_first(): void
    {
        // Regression test: real data shows an inconsistent field order --
        // Fukuoka's entries are "ika_fukuoka" but every other office's are
        // "{prefecture}_ika" -- both must resolve correctly.
        $download = $this->seedDownload(RhbCategory::Medical, [
            'r8_09_okinawa_ika_02.xlsx' => [['1', 'okinawa-facility']],
        ]);

        $units = (new KyushuBundleExpander)->expand($download);

        $this->assertCount(1, $units);
        $this->assertSame('47', $units[0]->prefectureCode);
    }

    public function test_ika_is_not_mistaken_for_shika_when_extracting_the_medical_entry(): void
    {
        // Regression test: "ika" is a substring of "shika", so a plain
        // substring match without underscore boundaries would let a
        // Medical-tagged download accidentally pick up the shika entry.
        $download = $this->seedDownload(RhbCategory::Medical, $this->allThreeCategoryEntries());

        $units = (new KyushuBundleExpander)->expand($download);

        $this->assertCount(1, $units);
        $this->assertStringNotContainsString('shika', $units[0]->xlsxPath);
        $this->assertStringContainsString('_ika_', $units[0]->xlsxPath);
    }

    public function test_no_matching_category_entry_throws_rather_than_being_silently_skipped(): void
    {
        $download = $this->seedDownload(RhbCategory::Pharmacy, [
            'r8_09_ika_fukuoka_02.xlsx' => [['1', 'medical-facility']],
        ]);

        $this->expectException(RuntimeException::class);

        (new KyushuBundleExpander)->expand($download);
    }

    public function test_an_unrecognized_prefecture_name_throws_rather_than_being_silently_skipped(): void
    {
        $download = $this->seedDownload(RhbCategory::Medical, [
            'r8_09_ika_nazoken_02.xlsx' => [['1', 'unknown']],
        ]);

        $this->expectException(RuntimeException::class);

        (new KyushuBundleExpander)->expand($download);
    }
}
