<?php

namespace App\Services\Mhlw\Sync;

use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use App\Models\MhlwDatasetDownload;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates one medical_facilities row from a Phase 2 row
 * mapper's output array, and records the corresponding
 * medical_facility_events entry. Does not resolve or diff departments
 * (DepartmentUpserter's job) and does not perform closure detection
 * (FacilityClosureReconciler's job) -- this only ever touches the one row
 * identified by $mappedAttributes['source_id'].
 */
final class FacilityUpserter
{
    /**
     * @param  array<string, mixed>  $mappedAttributes
     * @return array{facility: MedicalFacility, outcome: FacilityUpsertOutcome}
     */
    public function upsert(array $mappedAttributes, MhlwDatasetDownload $facilityDownload): array
    {
        $facility = MedicalFacility::where('source_id', $mappedAttributes['source_id'])->first();

        if ($facility === null) {
            return $this->create($mappedAttributes, $facilityDownload);
        }

        if ($facility->status === MedicalFacilityStatus::Closed) {
            return $this->reopen($facility, $mappedAttributes, $facilityDownload);
        }

        return $this->updateIfChanged($facility, $mappedAttributes, $facilityDownload);
    }

    /**
     * @param  array<string, mixed>  $mappedAttributes
     * @return array{facility: MedicalFacility, outcome: FacilityUpsertOutcome}
     */
    private function create(array $mappedAttributes, MhlwDatasetDownload $facilityDownload): array
    {
        return DB::transaction(function () use ($mappedAttributes, $facilityDownload) {
            $facility = MedicalFacility::create([
                ...$mappedAttributes,
                'status' => MedicalFacilityStatus::Active,
                'last_seen_mhlw_dataset_download_id' => $facilityDownload->id,
            ]);

            $this->recordEvent($facility, MedicalFacilityEventType::Created, $facilityDownload, [
                'name' => $facility->name,
                'address' => $facility->address,
            ]);

            return ['facility' => $facility, 'outcome' => FacilityUpsertOutcome::Created];
        });
    }

    /**
     * A facility whose status is currently Closed and reappears in a new
     * import gets a fresh Created event, not Updated -- "was this a
     * reopen" is meant to be derived later from the presence of an
     * earlier Removed event for the same facility, rather than needing a
     * dedicated event type or column.
     *
     * @param  array<string, mixed>  $mappedAttributes
     * @return array{facility: MedicalFacility, outcome: FacilityUpsertOutcome}
     */
    private function reopen(MedicalFacility $facility, array $mappedAttributes, MhlwDatasetDownload $facilityDownload): array
    {
        return DB::transaction(function () use ($facility, $mappedAttributes, $facilityDownload) {
            $facility->fill([
                ...$mappedAttributes,
                'status' => MedicalFacilityStatus::Active,
                'last_seen_mhlw_dataset_download_id' => $facilityDownload->id,
            ]);
            $facility->save();

            $this->recordEvent($facility, MedicalFacilityEventType::Created, $facilityDownload, [
                'name' => $facility->name,
                'address' => $facility->address,
            ]);

            return ['facility' => $facility, 'outcome' => FacilityUpsertOutcome::Reopened];
        });
    }

    /**
     * @param  array<string, mixed>  $mappedAttributes
     * @return array{facility: MedicalFacility, outcome: FacilityUpsertOutcome}
     */
    private function updateIfChanged(MedicalFacility $facility, array $mappedAttributes, MhlwDatasetDownload $facilityDownload): array
    {
        $current = [];

        foreach (array_keys($mappedAttributes) as $key) {
            $current[$key] = $facility->getAttribute($key);
        }

        $changes = AttributeDiff::diff($current, $mappedAttributes);

        return DB::transaction(function () use ($facility, $mappedAttributes, $facilityDownload, $changes) {
            $facility->fill([
                ...$mappedAttributes,
                'last_seen_mhlw_dataset_download_id' => $facilityDownload->id,
            ]);
            $facility->save();

            if ($changes === []) {
                return ['facility' => $facility, 'outcome' => FacilityUpsertOutcome::Unchanged];
            }

            $this->recordEvent($facility, MedicalFacilityEventType::Updated, $facilityDownload, $changes);

            return ['facility' => $facility, 'outcome' => FacilityUpsertOutcome::Updated];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(
        MedicalFacility $facility,
        MedicalFacilityEventType $type,
        MhlwDatasetDownload $facilityDownload,
        array $payload,
    ): void {
        MedicalFacilityEvent::create([
            'medical_facility_id' => $facility->id,
            'department_code' => null,
            'event_type' => $type,
            'occurred_on' => $facilityDownload->published_on,
            'payload' => $payload,
            'mhlw_dataset_download_id' => $facilityDownload->id,
        ]);
    }
}
