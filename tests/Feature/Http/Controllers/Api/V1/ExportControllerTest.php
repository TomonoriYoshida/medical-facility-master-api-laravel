<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\MedicalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['rhb.scope.prefectures' => ['13']]);
    }

    public function test_the_list_is_404_before_the_first_export(): void
    {
        $this->getJson('/api/v1/exports')
            ->assertNotFound()
            ->assertJsonPath('message', '一括ダウンロードのファイルはまだ作成されていません。');
    }

    public function test_lists_the_files_with_download_urls_and_attribution(): void
    {
        MedicalFacility::factory()->create(['prefecture_code' => '13']);
        $this->artisan('rhb:export')->assertExitCode(0);

        $response = $this->getJson('/api/v1/exports');

        $response->assertOk();
        $response->assertJsonCount(4, 'data.files');
        $response->assertJsonPath('data.files.0.name', 'medical-facilities-13.csv.gz');
        $response->assertJsonPath('data.files.0.url', route('api.v1.exports.show', ['filename' => 'medical-facilities-13.csv.gz']));
        $response->assertJsonPath('data.files.0.records', 1);
        $response->assertJsonPath('meta.attribution.license.name', '公共データ利用規約（第1.0版）');
    }

    public function test_downloads_a_listed_file_with_its_checksum_as_etag(): void
    {
        MedicalFacility::factory()->create(['prefecture_code' => '13']);
        $this->artisan('rhb:export')->assertExitCode(0);
        $sha256 = $this->getJson('/api/v1/exports')->json('data.files.1.sha256');

        $response = $this->get('/api/v1/exports/medical-facilities-13.jsonl.gz');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/gzip');
        $response->assertHeader('ETag', "\"{$sha256}\"");
        $this->assertSame($sha256, hash_file('sha256', $response->baseResponse->getFile()->getPathname()));
    }

    public function test_an_unchanged_file_is_answered_with_304(): void
    {
        MedicalFacility::factory()->create(['prefecture_code' => '13']);
        $this->artisan('rhb:export')->assertExitCode(0);
        $sha256 = $this->getJson('/api/v1/exports')->json('data.files.0.sha256');

        $response = $this->get('/api/v1/exports/medical-facilities-13.csv.gz', ['If-None-Match' => "\"{$sha256}\""]);

        $response->assertStatus(304);
    }

    public function test_only_listed_files_can_be_downloaded(): void
    {
        MedicalFacility::factory()->create(['prefecture_code' => '13']);
        $this->artisan('rhb:export')->assertExitCode(0);

        $this->get('/api/v1/exports/manifest.json')->assertNotFound();
        $this->get('/api/v1/exports/medical-facilities-27.csv.gz')->assertNotFound();
    }
}
