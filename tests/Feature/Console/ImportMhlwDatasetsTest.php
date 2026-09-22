<?php

namespace Tests\Feature\Console;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Jobs\ImportFacilityDatasetJob;
use App\Jobs\ImportSpecialityDatasetJob;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityDepartment;
use App\Models\MhlwDatasetDownload;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ImportMhlwDatasetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_facility_and_department_data_and_preserves_the_closure_ordering(): void
    {
        Storage::fake('local');

        $previousFacilityDownload = MhlwDatasetDownload::factory()->create(['dataset_key' => 'hospital_facility']);
        $previousSpecialityDownload = MhlwDatasetDownload::factory()->create(['dataset_key' => 'hospital_speciality']);

        $staleFacility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
            'last_seen_mhlw_dataset_download_id' => $previousFacilityDownload->id,
        ]);
        $staleDepartment = MedicalFacilityDepartment::factory()->create([
            'medical_facility_id' => $staleFacility->id,
            'last_seen_mhlw_dataset_download_id' => $previousSpecialityDownload->id,
        ]);

        $this->seedDownload('hospital_facility', [$this->hospitalRow('0001010000001', '新規病院')]);
        $this->seedDownload('hospital_speciality', [$this->specialityRow('0001010000001', '01001', '内科')]);

        $this->artisan('mhlw:import', ['--dataset' => ['hospital_facility', 'hospital_speciality'], '--wait' => true])
            ->assertExitCode(0);

        $newFacility = MedicalFacility::where('source_id', '0001010000001')->sole();
        $this->assertDatabaseHas('medical_facility_departments', [
            'medical_facility_id' => $newFacility->id,
            'department_code' => '01001',
        ]);

        $this->assertSame(MedicalFacilityStatus::Closed, $staleFacility->fresh()->status);

        // Carve-out: a department belonging to a facility closed earlier in
        // this same run is left alone by DepartmentClosureReconciler (its
        // facility is no longer Active, so it falls outside that
        // reconciler's scope entirely) -- proves the facility-before-
        // speciality chain ordering actually held end to end.
        $this->assertDatabaseHas('medical_facility_departments', ['id' => $staleDepartment->id]);
    }

    public function test_unknown_dataset_key_is_rejected(): void
    {
        $this->artisan('mhlw:import', ['--dataset' => ['not_a_real_key']])
            ->assertExitCode(1);
    }

    public function test_a_speciality_dataset_without_its_facility_pair_is_rejected(): void
    {
        $this->artisan('mhlw:import', ['--dataset' => ['hospital_speciality']])
            ->assertExitCode(1);
    }

    public function test_it_batches_three_chains_for_the_paired_institution_types_and_two_standalone_jobs(): void
    {
        Bus::fake();

        $this->artisan('mhlw:import')->assertExitCode(0);

        Bus::assertBatched(function (PendingBatch $batch): bool {
            $chains = $batch->jobs->filter(fn ($job) => is_array($job));
            $standalone = $batch->jobs->filter(fn ($job) => ! is_array($job));

            return $batch->jobs->count() === 5
                && $chains->count() === 3
                && $standalone->count() === 2
                && $chains->every(fn (array $chain) => count($chain) === 2
                    && $chain[0] instanceof ImportFacilityDatasetJob
                    && $chain[1] instanceof ImportSpecialityDatasetJob)
                && $standalone->every(fn ($job) => $job instanceof ImportFacilityDatasetJob);
        });
    }

    public function test_the_dataset_option_limits_the_batch_to_the_selected_datasets(): void
    {
        Bus::fake();

        $this->artisan('mhlw:import', ['--dataset' => ['pharmacy']])->assertExitCode(0);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
            && $batch->jobs[0] instanceof ImportFacilityDatasetJob
            && $batch->jobs[0]->datasetKey === 'pharmacy');
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

        $tmpZip = tempnam(sys_get_temp_dir(), 'import_command_test_').'.zip';
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
