<?php

namespace Tests\Feature\Console;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CheckRhbDatasetStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-29 07:00'));
        config(['rhb.stale_after_days' => 45]);
    }

    public function test_it_succeeds_when_every_dataset_is_imported_and_recent(): void
    {
        $this->createImportedDownloadsForEveryDataset();

        $this->artisan('rhb:status')
            ->expectsOutputToContain('すべてのデータセットが取込済みで最新です')
            ->assertExitCode(0);
    }

    public function test_a_dataset_that_was_never_downloaded_is_a_problem(): void
    {
        $this->createImportedDownloadsForEveryDataset();
        RhbDatasetDownload::where('bureau_code', RhbBureau::Kinki)
            ->where('category', RhbCategory::Pharmacy)
            ->delete();

        $this->artisan('rhb:status')
            ->expectsOutputToContain('未取得')
            ->expectsOutputToContain('1件の問題があります')
            ->assertExitCode(1);
    }

    public function test_a_current_download_that_is_not_imported_is_a_problem(): void
    {
        $this->createImportedDownloadsForEveryDataset();
        RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::Tohoku,
            'category' => RhbCategory::Medical,
            'published_on' => '2026-09-15',
            'imported_at' => null,
        ]);

        $this->artisan('rhb:status')
            ->expectsOutputToContain('未取込')
            ->assertExitCode(1);
    }

    public function test_an_unimported_download_superseded_by_a_newer_imported_one_is_not_a_problem(): void
    {
        $this->createImportedDownloadsForEveryDataset();
        RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::Tohoku,
            'category' => RhbCategory::Medical,
            'published_on' => '2026-08-01',
            'imported_at' => null,
        ]);

        $this->artisan('rhb:status')->assertExitCode(0);
    }

    public function test_a_dataset_not_updated_within_the_threshold_is_a_problem(): void
    {
        $this->createImportedDownloadsForEveryDataset();
        RhbDatasetDownload::where('bureau_code', RhbBureau::Kyushu)
            ->where('category', RhbCategory::Dental)
            ->update(['published_on' => '2026-08-01']);

        $this->artisan('rhb:status')
            ->expectsOutputToContain('59日間更新なし')
            ->assertExitCode(1);
    }

    public function test_a_dataset_exactly_at_the_threshold_is_not_yet_a_problem(): void
    {
        $this->createImportedDownloadsForEveryDataset();
        RhbDatasetDownload::where('bureau_code', RhbBureau::Kyushu)
            ->where('category', RhbCategory::Dental)
            ->update(['published_on' => '2026-08-15']);

        $this->artisan('rhb:status')->assertExitCode(0);
    }

    private function createImportedDownloadsForEveryDataset(): void
    {
        foreach (RhbBureau::cases() as $bureau) {
            foreach (RhbCategory::cases() as $category) {
                RhbDatasetDownload::factory()->create([
                    'bureau_code' => $bureau,
                    'category' => $category,
                    'published_on' => '2026-09-01',
                    'imported_at' => '2026-09-02 05:40:00',
                ]);
            }
        }
    }
}
