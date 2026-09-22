<?php

namespace Tests\Feature\Services\Mhlw\Sync;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Models\MedicalFacility;
use App\Models\MhlwDatasetDownload;
use App\Services\Mhlw\Sync\FacilityClosureReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityClosureReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_facility_not_touched_by_the_current_download_is_closed(): void
    {
        $previousDownload = MhlwDatasetDownload::factory()->create();
        $currentDownload = MhlwDatasetDownload::factory()->create(['published_on' => '2026-12-01']);

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
            'last_seen_mhlw_dataset_download_id' => $previousDownload->id,
        ]);

        $closed = (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, $currentDownload);

        $this->assertCount(1, $closed);
        $this->assertSame(MedicalFacilityStatus::Closed, $facility->fresh()->status);

        $this->assertDatabaseHas('medical_facility_events', [
            'medical_facility_id' => $facility->id,
            'event_type' => MedicalFacilityEventType::Removed,
            'occurred_on' => '2026-12-01',
            'mhlw_dataset_download_id' => $currentDownload->id,
        ]);
    }

    public function test_a_facility_with_no_watermark_at_all_is_closed(): void
    {
        $currentDownload = MhlwDatasetDownload::factory()->create();

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
            'last_seen_mhlw_dataset_download_id' => null,
        ]);

        (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, $currentDownload);

        $this->assertSame(MedicalFacilityStatus::Closed, $facility->fresh()->status);
    }

    public function test_a_facility_touched_by_the_current_download_is_left_alone(): void
    {
        $currentDownload = MhlwDatasetDownload::factory()->create();

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
            'last_seen_mhlw_dataset_download_id' => $currentDownload->id,
        ]);

        $closed = (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, $currentDownload);

        $this->assertCount(0, $closed);
        $this->assertSame(MedicalFacilityStatus::Active, $facility->fresh()->status);
        $this->assertSame(0, $facility->events()->count());
    }

    public function test_facilities_of_a_different_institution_type_are_not_affected(): void
    {
        $previousDownload = MhlwDatasetDownload::factory()->create();
        $currentDownload = MhlwDatasetDownload::factory()->create();

        $clinic = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'status' => MedicalFacilityStatus::Active,
            'last_seen_mhlw_dataset_download_id' => $previousDownload->id,
        ]);

        (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, $currentDownload);

        $this->assertSame(MedicalFacilityStatus::Active, $clinic->fresh()->status);
    }

    public function test_an_already_closed_facility_is_not_reprocessed(): void
    {
        $previousDownload = MhlwDatasetDownload::factory()->create();
        $currentDownload = MhlwDatasetDownload::factory()->create();

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Closed,
            'last_seen_mhlw_dataset_download_id' => $previousDownload->id,
        ]);

        $closed = (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, $currentDownload);

        $this->assertCount(0, $closed);
        $this->assertSame(0, $facility->events()->count());
    }
}
