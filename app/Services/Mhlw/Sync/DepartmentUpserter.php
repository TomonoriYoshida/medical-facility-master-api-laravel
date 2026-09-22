<?php

namespace App\Services\Mhlw\Sync;

use App\Enums\MedicalFacilityEventType;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityDepartment;
use App\Models\MedicalFacilityEvent;
use App\Models\MhlwDatasetDownload;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates one medical_facility_departments row from
 * App\Services\Mhlw\Import\SpecialityRowMapper's output, and records the
 * corresponding medical_facility_events entry. Does not resolve
 * source_id -> MedicalFacility itself; the caller (a future Phase 4 job)
 * must already have looked up and passed the owning facility, since
 * correlating rows across the separate facility-file and speciality-file
 * downloads is an orchestration concern, not a per-row upsert concern.
 *
 * No reopen branch: departments have no status column and are hard-
 * deleted on removal, so a department_code that isn't found is always
 * genuinely new, never a reappearing one.
 */
final class DepartmentUpserter
{
    /**
     * @param  array<string, mixed>  $mappedAttributes  as returned by SpecialityRowMapper::mapGroup() -- includes a "source_id" key that is NOT a real column and is stripped here
     * @return array{department: MedicalFacilityDepartment, outcome: DepartmentUpsertOutcome}
     */
    public function upsert(array $mappedAttributes, MedicalFacility $facility, MhlwDatasetDownload $specialityDownload): array
    {
        $attributes = Arr::except($mappedAttributes, ['source_id']);

        $department = MedicalFacilityDepartment::where('medical_facility_id', $facility->id)
            ->where('department_code', $attributes['department_code'])
            ->first();

        if ($department === null) {
            return $this->create($facility, $attributes, $specialityDownload);
        }

        return $this->updateIfChanged($facility, $department, $attributes, $specialityDownload);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{department: MedicalFacilityDepartment, outcome: DepartmentUpsertOutcome}
     */
    private function create(MedicalFacility $facility, array $attributes, MhlwDatasetDownload $specialityDownload): array
    {
        return DB::transaction(function () use ($facility, $attributes, $specialityDownload) {
            $department = MedicalFacilityDepartment::create([
                ...$attributes,
                'medical_facility_id' => $facility->id,
                'last_seen_mhlw_dataset_download_id' => $specialityDownload->id,
            ]);

            $this->recordEvent(
                $facility,
                $department->department_code,
                MedicalFacilityEventType::Created,
                $specialityDownload,
                ['department_name' => $department->department_name],
            );

            return ['department' => $department, 'outcome' => DepartmentUpsertOutcome::Created];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{department: MedicalFacilityDepartment, outcome: DepartmentUpsertOutcome}
     */
    private function updateIfChanged(
        MedicalFacility $facility,
        MedicalFacilityDepartment $department,
        array $attributes,
        MhlwDatasetDownload $specialityDownload,
    ): array {
        $current = [];

        foreach (array_keys($attributes) as $key) {
            $current[$key] = $department->getAttribute($key);
        }

        $changes = AttributeDiff::diff($current, $attributes);

        return DB::transaction(function () use ($facility, $department, $attributes, $specialityDownload, $changes) {
            $department->fill([
                ...$attributes,
                'last_seen_mhlw_dataset_download_id' => $specialityDownload->id,
            ]);
            $department->save();

            if ($changes === []) {
                return ['department' => $department, 'outcome' => DepartmentUpsertOutcome::Unchanged];
            }

            $this->recordEvent(
                $facility,
                $department->department_code,
                MedicalFacilityEventType::Updated,
                $specialityDownload,
                $changes,
            );

            return ['department' => $department, 'outcome' => DepartmentUpsertOutcome::Updated];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(
        MedicalFacility $facility,
        string $departmentCode,
        MedicalFacilityEventType $type,
        MhlwDatasetDownload $specialityDownload,
        array $payload,
    ): void {
        MedicalFacilityEvent::create([
            'medical_facility_id' => $facility->id,
            'department_code' => $departmentCode,
            'event_type' => $type,
            'occurred_on' => $specialityDownload->published_on,
            'payload' => $payload,
            'mhlw_dataset_download_id' => $specialityDownload->id,
        ]);
    }
}
