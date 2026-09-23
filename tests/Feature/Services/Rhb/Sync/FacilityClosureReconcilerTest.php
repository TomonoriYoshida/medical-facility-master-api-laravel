<?php

namespace Tests\Feature\Services\Rhb\Sync;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Models\MedicalFacility;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Sync\FacilityClosureReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityClosureReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_facility_not_touched_by_the_current_download_is_closed(): void
    {
        $previousDownload = RhbDatasetDownload::factory()->create();
        $currentDownload = RhbDatasetDownload::factory()->create(['published_on' => '2026-12-01']);

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'prefecture_code' => '01',
            'status' => MedicalFacilityStatus::Active,
            'last_seen_rhb_dataset_download_id' => $previousDownload->id,
        ]);

        $closed = (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, '01', $currentDownload);

        $this->assertCount(1, $closed);
        $this->assertSame(MedicalFacilityStatus::Closed, $facility->fresh()->status);

        $this->assertDatabaseHas('medical_facility_events', [
            'medical_facility_id' => $facility->id,
            'event_type' => MedicalFacilityEventType::Removed,
            'occurred_on' => '2026-12-01',
            'rhb_dataset_download_id' => $currentDownload->id,
        ]);
    }

    public function test_a_suspended_facility_not_touched_by_the_current_download_is_also_closed(): void
    {
        $previousDownload = RhbDatasetDownload::factory()->create();
        $currentDownload = RhbDatasetDownload::factory()->create();

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'prefecture_code' => '01',
            'status' => MedicalFacilityStatus::Suspended,
            'last_seen_rhb_dataset_download_id' => $previousDownload->id,
        ]);

        (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, '01', $currentDownload);

        $this->assertSame(MedicalFacilityStatus::Closed, $facility->fresh()->status);
    }

    public function test_a_facility_with_no_watermark_at_all_is_closed(): void
    {
        $currentDownload = RhbDatasetDownload::factory()->create();

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'prefecture_code' => '01',
            'status' => MedicalFacilityStatus::Active,
            'last_seen_rhb_dataset_download_id' => null,
        ]);

        (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, '01', $currentDownload);

        $this->assertSame(MedicalFacilityStatus::Closed, $facility->fresh()->status);
    }

    public function test_a_facility_touched_by_the_current_download_is_left_alone(): void
    {
        $currentDownload = RhbDatasetDownload::factory()->create();

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'prefecture_code' => '01',
            'status' => MedicalFacilityStatus::Active,
            'last_seen_rhb_dataset_download_id' => $currentDownload->id,
        ]);

        $closed = (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, '01', $currentDownload);

        $this->assertCount(0, $closed);
        $this->assertSame(MedicalFacilityStatus::Active, $facility->fresh()->status);
        $this->assertSame(0, $facility->events()->count());
    }

    public function test_facilities_of_a_different_institution_type_are_not_affected(): void
    {
        $previousDownload = RhbDatasetDownload::factory()->create();
        $currentDownload = RhbDatasetDownload::factory()->create();

        $clinic = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'prefecture_code' => '01',
            'status' => MedicalFacilityStatus::Active,
            'last_seen_rhb_dataset_download_id' => $previousDownload->id,
        ]);

        (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, '01', $currentDownload);

        $this->assertSame(MedicalFacilityStatus::Active, $clinic->fresh()->status);
    }

    public function test_facilities_of_a_different_prefecture_are_not_affected(): void
    {
        // A single rhb_dataset_downloads row can bundle multiple
        // prefectures (e.g. Tohoku's one file covering 6 prefectures), so
        // the stale-watermark check alone cannot prove a given prefecture
        // was actually supposed to appear in this run -- prefecture_code
        // must additionally scope the query.
        $previousDownload = RhbDatasetDownload::factory()->create();
        $currentDownload = RhbDatasetDownload::factory()->create();

        $otherPrefecture = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'prefecture_code' => '02',
            'status' => MedicalFacilityStatus::Active,
            'last_seen_rhb_dataset_download_id' => $previousDownload->id,
        ]);

        (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, '01', $currentDownload);

        $this->assertSame(MedicalFacilityStatus::Active, $otherPrefecture->fresh()->status);
    }

    public function test_an_already_closed_facility_is_not_reprocessed(): void
    {
        $previousDownload = RhbDatasetDownload::factory()->create();
        $currentDownload = RhbDatasetDownload::factory()->create();

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'prefecture_code' => '01',
            'status' => MedicalFacilityStatus::Closed,
            'last_seen_rhb_dataset_download_id' => $previousDownload->id,
        ]);

        $closed = (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, '01', $currentDownload);

        $this->assertCount(0, $closed);
        $this->assertSame(0, $facility->events()->count());
    }
}
