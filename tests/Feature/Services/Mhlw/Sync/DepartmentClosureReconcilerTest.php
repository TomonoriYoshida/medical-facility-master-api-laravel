<?php

namespace Tests\Feature\Services\Mhlw\Sync;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityDepartment;
use App\Models\MhlwDatasetDownload;
use App\Services\Mhlw\Sync\DepartmentClosureReconciler;
use App\Services\Mhlw\Sync\FacilityClosureReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentClosureReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_untouched_department_of_a_still_active_facility_is_removed_and_deleted(): void
    {
        $previousDownload = MhlwDatasetDownload::factory()->create();
        $currentDownload = MhlwDatasetDownload::factory()->create(['published_on' => '2026-12-01']);

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
        ]);
        $department = MedicalFacilityDepartment::factory()->create([
            'medical_facility_id' => $facility->id,
            'last_seen_mhlw_dataset_download_id' => $previousDownload->id,
        ]);

        $removed = (new DepartmentClosureReconciler)->reconcile(InstitutionType::Hospital, $currentDownload);

        $this->assertCount(1, $removed);
        $this->assertDatabaseMissing('medical_facility_departments', ['id' => $department->id]);
        $this->assertDatabaseHas('medical_facility_events', [
            'medical_facility_id' => $facility->id,
            'department_code' => $department->department_code,
            'event_type' => MedicalFacilityEventType::Removed,
            'occurred_on' => '2026-12-01',
        ]);
    }

    public function test_a_department_touched_by_the_current_download_is_left_alone(): void
    {
        $currentDownload = MhlwDatasetDownload::factory()->create();

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
        ]);
        $department = MedicalFacilityDepartment::factory()->create([
            'medical_facility_id' => $facility->id,
            'last_seen_mhlw_dataset_download_id' => $currentDownload->id,
        ]);

        $removed = (new DepartmentClosureReconciler)->reconcile(InstitutionType::Hospital, $currentDownload);

        $this->assertCount(0, $removed);
        $this->assertDatabaseHas('medical_facility_departments', ['id' => $department->id]);
    }

    public function test_departments_of_a_facility_closed_this_run_do_not_get_their_own_removed_event(): void
    {
        // The carve-out: closing a facility must not also cascade
        // individual department-level Removed events for it -- that would
        // multiply event volume disproportionately (a 20-department
        // hospital closing would otherwise produce 21 events for one real
        // change). FacilityClosureReconciler must run first.
        $previousFacilityDownload = MhlwDatasetDownload::factory()->create();
        $currentFacilityDownload = MhlwDatasetDownload::factory()->create();
        $previousSpecialityDownload = MhlwDatasetDownload::factory()->create();
        $currentSpecialityDownload = MhlwDatasetDownload::factory()->create();

        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
            'last_seen_mhlw_dataset_download_id' => $previousFacilityDownload->id,
        ]);
        $department = MedicalFacilityDepartment::factory()->create([
            'medical_facility_id' => $facility->id,
            'last_seen_mhlw_dataset_download_id' => $previousSpecialityDownload->id,
        ]);

        (new FacilityClosureReconciler)->reconcile(InstitutionType::Hospital, $currentFacilityDownload);
        $this->assertSame(MedicalFacilityStatus::Closed, $facility->fresh()->status);

        $removed = (new DepartmentClosureReconciler)->reconcile(InstitutionType::Hospital, $currentSpecialityDownload);

        $this->assertCount(0, $removed);
        $this->assertDatabaseHas('medical_facility_departments', ['id' => $department->id]);
        $this->assertDatabaseMissing('medical_facility_events', [
            'medical_facility_id' => $facility->id,
            'department_code' => $department->department_code,
            'event_type' => MedicalFacilityEventType::Removed,
        ]);
    }
}
