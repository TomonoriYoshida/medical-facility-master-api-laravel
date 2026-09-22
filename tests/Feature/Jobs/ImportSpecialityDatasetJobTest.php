<?php

namespace Tests\Feature\Jobs;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Jobs\ImportSpecialityDatasetJob;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityDepartment;
use App\Models\MhlwDatasetDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ImportSpecialityDatasetJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_departments_for_a_known_facility(): void
    {
        Storage::fake('local');
        $facility = MedicalFacility::factory()->create([
            'source_id' => '0001010000001',
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
        ]);

        $this->seedDownload('hospital_speciality', [
            $this->specialityRow('0001010000001', '01001', '内科'),
        ]);

        ImportSpecialityDatasetJob::dispatch('hospital_speciality');

        $this->assertDatabaseHas('medical_facility_departments', [
            'medical_facility_id' => $facility->id,
            'department_code' => '01001',
            'department_name' => '内科',
        ]);
    }

    public function test_a_group_with_an_unknown_source_id_is_skipped_not_thrown(): void
    {
        Storage::fake('local');
        $facility = MedicalFacility::factory()->create([
            'source_id' => '0001010000001',
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
        ]);

        $this->seedDownload('hospital_speciality', [
            $this->specialityRow('9999999999999', '01001', '不明'),
            $this->specialityRow('0001010000001', '02001', '外科'),
        ]);

        ImportSpecialityDatasetJob::dispatch('hospital_speciality');

        $this->assertDatabaseCount('medical_facility_departments', 1);
        $this->assertDatabaseHas('medical_facility_departments', [
            'medical_facility_id' => $facility->id,
            'department_code' => '02001',
        ]);
    }

    public function test_it_closes_a_department_not_touched_by_this_run(): void
    {
        Storage::fake('local');
        $facility = MedicalFacility::factory()->create([
            'source_id' => '0001010000001',
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
        ]);
        $previousDownload = MhlwDatasetDownload::factory()->create(['dataset_key' => 'hospital_speciality']);
        $staleDepartment = MedicalFacilityDepartment::factory()->create([
            'medical_facility_id' => $facility->id,
            'last_seen_mhlw_dataset_download_id' => $previousDownload->id,
        ]);

        $this->seedDownload('hospital_speciality', [
            $this->specialityRow('0001010000001', '02001', '外科'),
        ]);

        ImportSpecialityDatasetJob::dispatch('hospital_speciality');

        $this->assertDatabaseMissing('medical_facility_departments', ['id' => $staleDepartment->id]);
    }

    /**
     * @return array<int, string>
     */
    private function specialityRow(string $sourceId, string $departmentCode, string $departmentName): array
    {
        $row = array_fill(0, 36, '');
        $row[0] = $sourceId;
        $row[1] = $departmentCode;
        $row[2] = $departmentName;
        $row[3] = '1';

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

        $tmpZip = tempnam(sys_get_temp_dir(), 'import_speciality_job_test_').'.zip';
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
