<?php

namespace App\Services\Rhb\Sync;

use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use App\Models\RhbDatasetDownload;
use App\Services\Sync\AttributeDiff;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates one medical_facilities row from an
 * InsuredFacilityRecordMapper output array, and records the corresponding
 * medical_facility_events entry. Does not perform closure detection
 * (FacilityClosureReconciler's job) -- this only ever touches the one row
 * identified by (bureau_code, facility_code).
 */
final class FacilityUpserter
{
    /**
     * @param  array<string, mixed>  $mappedAttributes
     * @return array{facility: MedicalFacility, outcome: FacilityUpsertOutcome}
     */
    public function upsert(array $mappedAttributes, RhbDatasetDownload $download): array
    {
        $facility = MedicalFacility::where('bureau_code', $mappedAttributes['bureau_code'])
            ->where('facility_code', $mappedAttributes['facility_code'])
            ->first();

        if ($facility === null) {
            return $this->create($mappedAttributes, $download);
        }

        if ($facility->status === MedicalFacilityStatus::Closed) {
            return $this->reopen($facility, $mappedAttributes, $download);
        }

        return $this->updateIfChanged($facility, $mappedAttributes, $download);
    }

    /**
     * @param  array<string, mixed>  $mappedAttributes
     * @return array{facility: MedicalFacility, outcome: FacilityUpsertOutcome}
     */
    private function create(array $mappedAttributes, RhbDatasetDownload $download): array
    {
        return DB::transaction(function () use ($mappedAttributes, $download) {
            $facility = MedicalFacility::create([
                ...$mappedAttributes,
                'last_seen_rhb_dataset_download_id' => $download->id,
            ]);

            $this->recordEvent($facility, MedicalFacilityEventType::Created, $download, [
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
     * dedicated event type or column. Its resulting status is whatever
     * the reappearing data says (Active or Suspended), not forced Active.
     *
     * @param  array<string, mixed>  $mappedAttributes
     * @return array{facility: MedicalFacility, outcome: FacilityUpsertOutcome}
     */
    private function reopen(MedicalFacility $facility, array $mappedAttributes, RhbDatasetDownload $download): array
    {
        return DB::transaction(function () use ($facility, $mappedAttributes, $download) {
            $facility->fill([
                ...$mappedAttributes,
                'last_seen_rhb_dataset_download_id' => $download->id,
            ]);
            $facility->save();

            $this->recordEvent($facility, MedicalFacilityEventType::Created, $download, [
                'name' => $facility->name,
                'address' => $facility->address,
            ]);

            return ['facility' => $facility, 'outcome' => FacilityUpsertOutcome::Reopened];
        });
    }

    /**
     * Covers both Active and Suspended facilities -- the Active<->Suspended
     * transition is not a special case, just an ordinary diffed change to
     * the status attribute captured in the Updated event's payload.
     *
     * @param  array<string, mixed>  $mappedAttributes
     * @return array{facility: MedicalFacility, outcome: FacilityUpsertOutcome}
     */
    private function updateIfChanged(MedicalFacility $facility, array $mappedAttributes, RhbDatasetDownload $download): array
    {
        $current = [];

        foreach (array_keys($mappedAttributes) as $key) {
            $current[$key] = $facility->getAttribute($key);
        }

        $changes = AttributeDiff::diff($current, $mappedAttributes);

        return DB::transaction(function () use ($facility, $mappedAttributes, $download, $changes) {
            $facility->fill([
                ...$mappedAttributes,
                'last_seen_rhb_dataset_download_id' => $download->id,
            ]);
            $facility->save();

            if ($changes === []) {
                return ['facility' => $facility, 'outcome' => FacilityUpsertOutcome::Unchanged];
            }

            $this->recordEvent($facility, MedicalFacilityEventType::Updated, $download, $changes);

            return ['facility' => $facility, 'outcome' => FacilityUpsertOutcome::Updated];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(
        MedicalFacility $facility,
        MedicalFacilityEventType $type,
        RhbDatasetDownload $download,
        array $payload,
    ): void {
        MedicalFacilityEvent::create([
            'medical_facility_id' => $facility->id,
            'event_type' => $type,
            'occurred_on' => $download->published_on,
            'payload' => $payload,
            'rhb_dataset_download_id' => $download->id,
        ]);
    }
}
