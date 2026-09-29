<?php

namespace Tests\Feature\Console;

use App\Enums\InstitutionType;
use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use App\Models\RhbDatasetDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneOutOfScopeRhbDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_deletes_facilities_and_their_events_outside_the_scoped_prefectures(): void
    {
        $kept = $this->facilityWithEvent('02', InstitutionType::Clinic);
        $removed = $this->facilityWithEvent('03', InstitutionType::Clinic);
        config(['rhb.scope.prefectures' => ['02']]);

        $this->artisan('rhb:prune', ['--force' => true])->assertExitCode(0);

        $this->assertModelExists($kept);
        $this->assertModelMissing($removed);
        $this->assertSame(1, MedicalFacilityEvent::count());
    }

    public function test_it_deletes_facilities_of_categories_outside_the_scope(): void
    {
        $pharmacy = $this->facilityWithEvent('02', InstitutionType::Pharmacy);
        $hospital = $this->facilityWithEvent('02', InstitutionType::Hospital);
        config(['rhb.scope.categories' => ['pharmacy']]);

        $this->artisan('rhb:prune', ['--force' => true])->assertExitCode(0);

        $this->assertModelExists($pharmacy);
        $this->assertModelMissing($hospital);
    }

    public function test_it_deletes_downloads_and_files_no_longer_covering_the_scope(): void
    {
        $tohoku = $this->download(RhbBureau::Tohoku, RhbCategory::Medical, ['02', '03', '04', '05', '06', '07'], 'rhb/tohoku/medical/tohoku.xlsx');
        $kyushuSaga = $this->download(RhbBureau::Kyushu, RhbCategory::Medical, ['41'], 'rhb/kyushu/medical/saga.zip');
        $kyushuFukuoka = $this->download(RhbBureau::Kyushu, RhbCategory::Medical, ['40'], 'rhb/kyushu/medical/fukuoka.zip');
        $tohokuPharmacy = $this->download(RhbBureau::Tohoku, RhbCategory::Pharmacy, ['02', '03', '04', '05', '06', '07'], 'rhb/tohoku/pharmacy/tohoku.xlsx');
        Storage::disk('local')->put("rhb/kyushu/extracted/{$kyushuSaga->id}/entry.xlsx", 'x');
        config(['rhb.scope.prefectures' => ['03', '40'], 'rhb.scope.categories' => ['medical']]);

        $this->artisan('rhb:prune', ['--force' => true])->assertExitCode(0);

        $this->assertModelExists($tohoku);
        $this->assertModelExists($kyushuFukuoka);
        $this->assertModelMissing($kyushuSaga);
        $this->assertModelMissing($tohokuPharmacy);
        Storage::disk('local')->assertExists('rhb/tohoku/medical/tohoku.xlsx');
        Storage::disk('local')->assertMissing('rhb/kyushu/medical/saga.zip');
        Storage::disk('local')->assertMissing("rhb/kyushu/extracted/{$kyushuSaga->id}/entry.xlsx");
        Storage::disk('local')->assertMissing('rhb/tohoku/pharmacy/tohoku.xlsx');
    }

    public function test_a_file_still_used_by_a_kept_download_is_not_deleted(): void
    {
        // Hokkaido keeps one filename across monthly versions.
        $this->download(RhbBureau::Kyushu, RhbCategory::Medical, ['41'], 'rhb/shared/file.xlsx');
        $this->download(RhbBureau::Kyushu, RhbCategory::Medical, ['40'], 'rhb/shared/file.xlsx');
        config(['rhb.scope.prefectures' => ['40']]);

        $this->artisan('rhb:prune', ['--force' => true])->assertExitCode(0);

        Storage::disk('local')->assertExists('rhb/shared/file.xlsx');
    }

    public function test_dry_run_only_reports_the_counts(): void
    {
        $removed = $this->facilityWithEvent('03', InstitutionType::Clinic);
        config(['rhb.scope.prefectures' => ['02']]);

        $this->artisan('rhb:prune', ['--dry-run' => true])
            ->expectsOutputToContain('範囲外の施設')
            ->assertExitCode(0);

        $this->assertModelExists($removed);
    }

    public function test_nothing_is_deleted_when_the_confirmation_is_declined(): void
    {
        $removed = $this->facilityWithEvent('03', InstitutionType::Clinic);
        config(['rhb.scope.prefectures' => ['02']]);

        $this->artisan('rhb:prune')
            ->expectsConfirmation('これらを削除します。元に戻せません。続けますか？', 'no')
            ->assertExitCode(1);

        $this->assertModelExists($removed);
    }

    public function test_it_reports_when_everything_is_within_the_scope(): void
    {
        $this->facilityWithEvent('02', InstitutionType::Clinic);

        $this->artisan('rhb:prune')
            ->expectsOutputToContain('範囲外のデータはありません')
            ->assertExitCode(0);
    }

    private function facilityWithEvent(string $prefectureCode, InstitutionType $institutionType): MedicalFacility
    {
        $facility = MedicalFacility::factory()->create([
            'prefecture_code' => $prefectureCode,
            'institution_type' => $institutionType,
        ]);
        MedicalFacilityEvent::factory()->create(['medical_facility_id' => $facility->id]);

        return $facility;
    }

    /**
     * @param  list<string>  $prefectureCodes
     */
    private function download(RhbBureau $bureau, RhbCategory $category, array $prefectureCodes, string $localPath): RhbDatasetDownload
    {
        Storage::disk('local')->put($localPath, 'x');

        return RhbDatasetDownload::factory()->create([
            'bureau_code' => $bureau,
            'category' => $category,
            'prefecture_codes' => $prefectureCodes,
            'local_path' => $localPath,
        ]);
    }
}
