<?php

namespace Tests\Feature\Jobs;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Jobs\ImportFacilityDatasetJob;
use App\Models\MedicalFacility;
use App\Models\MhlwDatasetDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ImportFacilityDatasetJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_new_facilities_from_the_downloaded_csv(): void
    {
        Storage::fake('local');
        $this->seedDownload('hospital_facility', [
            $this->hospitalRow('0001010000001', '病院A'),
            $this->hospitalRow('0001010000002', '病院B'),
        ]);

        ImportFacilityDatasetJob::dispatch('hospital_facility');

        $this->assertDatabaseCount('medical_facilities', 2);
        $this->assertDatabaseHas('medical_facilities', [
            'source_id' => '0001010000001',
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
        ]);
    }

    public function test_it_closes_a_facility_not_touched_by_this_run(): void
    {
        Storage::fake('local');
        $previousDownload = MhlwDatasetDownload::factory()->create(['dataset_key' => 'hospital_facility']);
        $staleFacility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
            'last_seen_mhlw_dataset_download_id' => $previousDownload->id,
        ]);

        $this->seedDownload('hospital_facility', [
            $this->hospitalRow('0001010000009', '新しい病院'),
        ]);

        ImportFacilityDatasetJob::dispatch('hospital_facility');

        $this->assertSame(MedicalFacilityStatus::Closed, $staleFacility->fresh()->status);
    }

    public function test_it_does_nothing_when_no_download_is_recorded(): void
    {
        Storage::fake('local');

        ImportFacilityDatasetJob::dispatch('hospital_facility');

        $this->assertDatabaseCount('medical_facilities', 0);
    }

    public function test_a_malformed_row_does_not_prevent_the_rest_from_being_imported(): void
    {
        Storage::fake('local');
        $this->seedDownload('hospital_facility', [
            $this->hospitalRow('0001010000001', '病院A'),
            $this->hospitalRow('0001010000002', str_repeat('あ', 300)),
            $this->hospitalRow('0001010000003', '病院C'),
        ]);

        ImportFacilityDatasetJob::dispatch('hospital_facility');

        $this->assertDatabaseHas('medical_facilities', ['source_id' => '0001010000001']);
        $this->assertDatabaseHas('medical_facilities', ['source_id' => '0001010000003']);
        $this->assertDatabaseMissing('medical_facilities', ['source_id' => '0001010000002']);
    }

    /**
     * @return array<int, string>
     */
    private function hospitalRow(string $sourceId, string $name): array
    {
        $row = array_fill(0, 65, '');
        $row[0] = $sourceId;
        $row[1] = $name;
        $row[7] = '01';
        $row[8] = '101';
        $row[9] = '住所';

        return $row;
    }

    /**
     * @param  list<array<int, string>>  $rows
     */
    private function seedDownload(string $datasetKey, array $rows): MhlwDatasetDownload
    {
        $filename = "{$datasetKey}_20260601.csv.zip";
        $localPath = "mhlw/{$datasetKey}/{$filename}";
        $entryName = "{$datasetKey}_20260601.csv";

        $csv = "header\r\n";

        foreach ($rows as $row) {
            $csv .= implode(',', $row)."\r\n";
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'import_facility_job_test_').'.zip';
        $zip = new ZipArchive;
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($entryName, $csv);
        $zip->close();

        Storage::disk('local')->put($localPath, file_get_contents($tmpZip));
        @unlink($tmpZip);

        return MhlwDatasetDownload::create([
            'dataset_key' => $datasetKey,
            'filename' => $filename,
            'published_on' => '2026-06-01',
            'source_url' => "https://example.com/{$filename}",
            'local_path' => $localPath,
            'downloaded_at' => now(),
        ]);
    }
}
