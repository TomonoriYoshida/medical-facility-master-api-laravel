<?php

namespace Tests\Feature\Jobs;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Jobs\ImportRhbFacilityListJob;
use App\Models\MedicalFacility;
use App\Models\RhbDatasetDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsXlsxFixture;
use Tests\TestCase;

class ImportRhbFacilityListJobTest extends TestCase
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

    public function test_it_creates_facilities_from_the_downloaded_xlsx(): void
    {
        $this->seedDownload(RhbCategory::Medical, [
            $this->hospitalRow('1', '0111000', '病院A'),
            $this->hospitalRow('2', '0111001', '病院B'),
        ]);

        ImportRhbFacilityListJob::dispatch(RhbBureau::Hokkaido, RhbCategory::Medical);

        $this->assertDatabaseCount('medical_facilities', 2);
        $this->assertDatabaseHas('medical_facilities', [
            'facility_code' => '0111000',
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
        ]);
    }

    public function test_it_closes_a_facility_not_touched_by_this_run(): void
    {
        // The "previous" download shares the same filename as the current
        // one (Hokkaido's document IDs are stable across monthly updates)
        // but an earlier published_on -- allFor() must treat only the
        // later-published row as current.
        $previousDownload = RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::Hokkaido,
            'category' => RhbCategory::Medical,
            'filename' => 'hospital.xlsx',
            'published_on' => '2026-05-01',
        ]);
        $staleFacility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'prefecture_code' => '01',
            'status' => MedicalFacilityStatus::Active,
            'last_seen_rhb_dataset_download_id' => $previousDownload->id,
        ]);

        $this->seedDownload(RhbCategory::Medical, [
            $this->hospitalRow('1', '0119999', '新しい病院'),
        ], filename: 'hospital.xlsx', publishedOn: '2026-06-01');

        ImportRhbFacilityListJob::dispatch(RhbBureau::Hokkaido, RhbCategory::Medical);

        $this->assertSame(MedicalFacilityStatus::Closed, $staleFacility->fresh()->status);
    }

    public function test_a_previous_months_file_with_a_different_filename_is_not_reimported(): void
    {
        // Kanto-Shinetsu/Tohoku embed the month in the filename
        // ("..._r{YYMM}..."), so last month's download does not share a
        // filename with this month's -- it must still be treated as stale.
        $this->seedDownload(RhbCategory::Medical, [
            $this->hospitalRow('1', '0111000', '病院A'),
        ], filename: 'shitei_ika_r0805.xlsx', publishedOn: '2026-05-01');
        ImportRhbFacilityListJob::dispatch(RhbBureau::Hokkaido, RhbCategory::Medical);

        $this->seedDownload(RhbCategory::Medical, [
            $this->hospitalRow('1', '0111000', '病院A改'),
            $this->hospitalRow('2', '0111001', '新規病院'),
        ], filename: 'shitei_ika_r0806.xlsx', publishedOn: '2026-06-01');
        ImportRhbFacilityListJob::dispatch(RhbBureau::Hokkaido, RhbCategory::Medical);

        $this->assertDatabaseHas('medical_facilities', [
            'facility_code' => '0111000',
            'name' => '病院A改',
        ]);
        $this->assertDatabaseHas('medical_facilities', [
            'facility_code' => '0111001',
            'status' => MedicalFacilityStatus::Active,
        ]);
    }

    public function test_it_does_nothing_when_no_download_is_recorded(): void
    {
        ImportRhbFacilityListJob::dispatch(RhbBureau::Hokkaido, RhbCategory::Medical);

        $this->assertDatabaseCount('medical_facilities', 0);
    }

    public function test_a_malformed_row_does_not_prevent_the_rest_from_being_imported(): void
    {
        $this->seedDownload(RhbCategory::Medical, [
            $this->hospitalRow('1', '0111000', '病院A'),
            $this->hospitalRow('2', '0111001', str_repeat('あ', 300)),
            $this->hospitalRow('3', '0111002', '病院C'),
        ]);

        ImportRhbFacilityListJob::dispatch(RhbBureau::Hokkaido, RhbCategory::Medical);

        $this->assertDatabaseHas('medical_facilities', ['facility_code' => '0111000']);
        $this->assertDatabaseHas('medical_facilities', ['facility_code' => '0111002']);
        $this->assertDatabaseMissing('medical_facilities', ['facility_code' => '0111001']);
    }

    public function test_an_existing_facility_whose_row_fails_to_upsert_is_not_closed(): void
    {
        $this->seedDownload(RhbCategory::Medical, [
            $this->hospitalRow('1', '0111000', '病院A'),
            $this->hospitalRow('2', '0111001', '病院B'),
            $this->hospitalRow('3', '0111002', '病院C'),
        ], filename: 'hospital.xlsx', publishedOn: '2026-05-01');
        ImportRhbFacilityListJob::dispatch(RhbBureau::Hokkaido, RhbCategory::Medical);

        // 病院A is still listed but its row now fails to upsert; 病院C is
        // genuinely gone and must still be closed.
        $this->seedDownload(RhbCategory::Medical, [
            $this->hospitalRow('1', '0111000', str_repeat('あ', 300)),
            $this->hospitalRow('2', '0111001', '病院B'),
        ], filename: 'hospital.xlsx', publishedOn: '2026-06-01');
        ImportRhbFacilityListJob::dispatch(RhbBureau::Hokkaido, RhbCategory::Medical);

        $failed = MedicalFacility::where('facility_code', '0111000')->first();
        $this->assertSame(MedicalFacilityStatus::Active, $failed->status);
        $this->assertSame('病院A', $failed->name);
        $this->assertFalse($failed->events()->where('event_type', MedicalFacilityEventType::Removed)->exists());

        $this->assertDatabaseHas('medical_facilities', [
            'facility_code' => '0111002',
            'status' => MedicalFacilityStatus::Closed,
        ]);
    }

    public function test_hospital_and_clinic_files_under_the_same_medical_category_are_both_processed_and_reconciled_separately(): void
    {
        // Hokkaido publishes hospital and clinic data as two separate
        // files, both RhbCategory::Medical. Both must be processed by the
        // same job invocation, and closure reconciliation must run
        // separately per institution type against each file's own
        // download -- otherwise processing the hospital file alone would
        // incorrectly close every clinic (they were never in that file).
        $previousClinicDownload = RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::Hokkaido,
            'category' => RhbCategory::Medical,
            'filename' => 'clinic.xlsx',
            'published_on' => '2026-05-01',
        ]);
        $staleClinic = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'prefecture_code' => '01',
            'status' => MedicalFacilityStatus::Active,
            'last_seen_rhb_dataset_download_id' => $previousClinicDownload->id,
        ]);

        $this->seedDownload(RhbCategory::Medical, [
            $this->hospitalRow('1', '0111000', '病院A'),
        ], filename: 'hospital.xlsx', publishedOn: '2026-06-01');

        $this->seedDownload(RhbCategory::Medical, [
            $this->clinicRow('1', '0112000', 'クリニックA'),
        ], filename: 'clinic.xlsx', publishedOn: '2026-06-01');

        ImportRhbFacilityListJob::dispatch(RhbBureau::Hokkaido, RhbCategory::Medical);

        $this->assertDatabaseHas('medical_facilities', ['facility_code' => '0111000', 'institution_type' => InstitutionType::Hospital]);
        $this->assertDatabaseHas('medical_facilities', ['facility_code' => '0112000', 'institution_type' => InstitutionType::Clinic]);

        // The stale clinic (from a previous, different clinic download)
        // must be closed by the reconciliation pass for its own file.
        $this->assertSame(MedicalFacilityStatus::Closed, $staleClinic->fresh()->status);
    }

    /**
     * @return array<int, string>
     */
    private function hospitalRow(string $serial, string $code, string $name): array
    {
        return $this->buildRow($serial, $code, $name, '病院');
    }

    /**
     * @return array<int, string>
     */
    private function clinicRow(string $serial, string $code, string $name): array
    {
        return $this->buildRow($serial, $code, $name, '診療所');
    }

    /**
     * @return array<int, string>
     */
    private function buildRow(string $serial, string $code, string $name, string $typeLabel): array
    {
        return [
            0 => $serial,
            1 => $code,
            2 => $name,
            3 => '〒060－0000札幌市中央区',
            4 => '011-000-0000',
            5 => '開設者',
            6 => '管理者',
            7 => '昭50. 1. 1',
            8 => '内',
            9 => $typeLabel,
        ];
    }

    /**
     * @param  list<array<int, string>>  $rows
     */
    private function seedDownload(RhbCategory $category, array $rows, string $filename = 'test.xlsx', string $publishedOn = '2026-06-01'): RhbDatasetDownload
    {
        $tempPath = $this->createXlsx([['header'], ...$rows]);
        $localPath = "rhb/hokkaido/medical/{$filename}";

        Storage::disk('local')->put($localPath, file_get_contents($tempPath));

        return RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::Hokkaido,
            'category' => $category,
            'prefecture_codes' => ['01'],
            'filename' => $filename,
            'local_path' => $localPath,
            'published_on' => $publishedOn,
        ]);
    }
}
