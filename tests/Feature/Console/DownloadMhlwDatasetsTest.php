<?php

namespace Tests\Feature\Console;

use App\Models\MhlwDatasetDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadMhlwDatasetsTest extends TestCase
{
    use RefreshDatabase;

    private const string INDEX_URL = 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/kenkou_iryou/iryou/newpage_43373.html';

    private const array DATASET_KEYS = [
        'hospital_facility',
        'hospital_speciality',
        'clinic_facility',
        'clinic_speciality',
        'dental_facility',
        'dental_speciality',
        'maternity_home',
        'pharmacy',
    ];

    private const string ZIP_BODY = "PK\x03\x04fake-zip-content";

    private string $indexHtml = '';

    /** @var list<string> */
    private array $failingUrls = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Http::fake() stub callbacks are cumulative across calls within a
        // test (later calls do not replace earlier ones), so a single fake
        // is registered here and each test mutates $this->indexHtml /
        // $this->failingUrls between artisan() calls instead of re-faking.
        Http::fake(function (Request $request) {
            if ($request->url() === self::INDEX_URL) {
                return Http::response($this->indexHtml);
            }

            if (in_array($request->url(), $this->failingUrls, true)) {
                return Http::response(status: 500);
            }

            return Http::response(self::ZIP_BODY);
        });
    }

    public function test_it_downloads_every_dataset_on_first_run(): void
    {
        Storage::fake('local');
        $this->fakeHttp($this->fixture('mhlw-index-initial.html'));

        $this->artisan('mhlw:download')->assertExitCode(0);

        $this->assertDatabaseCount('mhlw_dataset_downloads', 8);

        foreach (self::DATASET_KEYS as $key) {
            $download = MhlwDatasetDownload::where('dataset_key', $key)->sole();
            Storage::disk('local')->assertExists($download->local_path);
        }
    }

    public function test_it_skips_datasets_already_downloaded_at_the_current_version(): void
    {
        Storage::fake('local');
        $this->fakeHttp($this->fixture('mhlw-index-initial.html'));

        $this->artisan('mhlw:download')->assertExitCode(0);
        $this->artisan('mhlw:download')->assertExitCode(0);

        $this->assertDatabaseCount('mhlw_dataset_downloads', 8);
        Http::assertSentCount(9 + 1);
    }

    public function test_it_downloads_only_the_dataset_with_a_newer_snapshot(): void
    {
        Storage::fake('local');
        $this->fakeHttp($this->fixture('mhlw-index-initial.html'));
        $this->artisan('mhlw:download')->assertExitCode(0);

        $this->fakeHttp($this->fixture('mhlw-index-updated.html'));
        $this->artisan('mhlw:download')->assertExitCode(0);

        $this->assertDatabaseCount('mhlw_dataset_downloads', 9);

        $hospitalFacilityVersions = MhlwDatasetDownload::where('dataset_key', 'hospital_facility')->count();
        $this->assertSame(2, $hospitalFacilityVersions);

        $latest = MhlwDatasetDownload::latestFor('hospital_facility');
        $this->assertSame('01-1_hospital_facility_info_20261201.csv.zip', $latest->filename);
    }

    public function test_one_dataset_failing_does_not_prevent_the_others_from_downloading(): void
    {
        Storage::fake('local');
        $this->fakeHttp(
            $this->fixture('mhlw-index-initial.html'),
            failingUrls: ['https://www.mhlw.go.jp/content/11121000/05_pharmacy_20260601.csv.zip'],
        );

        $this->artisan('mhlw:download')->assertExitCode(1);

        $this->assertDatabaseCount('mhlw_dataset_downloads', 7);
        $this->assertDatabaseMissing('mhlw_dataset_downloads', ['dataset_key' => 'pharmacy']);
    }

    /**
     * @param  list<string>  $failingUrls
     */
    private function fakeHttp(string $indexHtml, array $failingUrls = []): void
    {
        $this->indexHtml = $indexHtml;
        $this->failingUrls = $failingUrls;
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/{$name}"));
    }
}
