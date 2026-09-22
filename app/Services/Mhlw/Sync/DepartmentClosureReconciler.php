<?php

namespace App\Services\Mhlw\Sync;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Models\MedicalFacilityDepartment;
use App\Models\MedicalFacilityEvent;
use App\Models\MhlwDatasetDownload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds every department (of facilities that are still Active for the
 * given institution type) that a given import run's speciality-file
 * download did NOT touch, records a Removed event for each, then hard-
 * deletes the row (departments have no status column, per DATABASE.md).
 *
 * Deliberately re-derives "which facilities are still Active" itself
 * (scoped via whereHas(), not a caller-supplied id list or Eloquent
 * collection) rather than accepting one from the caller: this makes the
 * "don't cascade department-level Removed events when the facility
 * itself just closed" carve-out automatic -- a facility
 * FacilityClosureReconciler flipped to Closed earlier in the same run is
 * excluded by the status=Active filter with no extra plumbing. This is
 * ALSO why FacilityClosureReconciler must run (and its writes must be
 * committed) before this class is called for the same institution type
 * and import run -- Phase 4's job chain owns enforcing that order, not
 * this class.
 *
 * $specialityDownload must be the *_speciality-dataset-key download for
 * $institutionType (e.g. "hospital_speciality"), never the corresponding
 * *_facility download -- see FacilityClosureReconciler's docblock for why
 * this distinction matters.
 */
final class DepartmentClosureReconciler
{
    private const int CHUNK_SIZE = 500;

    /**
     * @return Collection<int, MedicalFacilityDepartment>
     */
    public function reconcile(InstitutionType $institutionType, MhlwDatasetDownload $specialityDownload): Collection
    {
        $closed = collect();

        MedicalFacilityDepartment::query()
            ->whereHas('medicalFacility', function ($query) use ($institutionType) {
                $query->where('institution_type', $institutionType)
                    ->where('status', MedicalFacilityStatus::Active);
            })
            ->where(function ($query) use ($specialityDownload) {
                $query->whereNull('last_seen_mhlw_dataset_download_id')
                    ->orWhere('last_seen_mhlw_dataset_download_id', '!=', $specialityDownload->id);
            })
            ->chunkById(self::CHUNK_SIZE, function (Collection $departments) use ($specialityDownload, $closed) {
                foreach ($departments as $department) {
                    DB::transaction(function () use ($department, $specialityDownload) {
                        MedicalFacilityEvent::create([
                            'medical_facility_id' => $department->medical_facility_id,
                            'department_code' => $department->department_code,
                            'event_type' => MedicalFacilityEventType::Removed,
                            'occurred_on' => $specialityDownload->published_on,
                            'payload' => ['department_name' => $department->department_name],
                            'mhlw_dataset_download_id' => $specialityDownload->id,
                        ]);

                        $department->delete();
                    });

                    $closed->push($department);
                }
            });

        return $closed;
    }
}
