<?php

namespace App\Services\Mhlw\Sync;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use App\Models\MhlwDatasetDownload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds every currently-Active facility of one institution type that a
 * given import run's facility-file download did NOT touch (its
 * last_seen_mhlw_dataset_download_id is stale or null), marks it Closed,
 * and records a Removed event for each.
 *
 * IMPORTANT: $facilityDownload must be the *_facility-dataset-key
 * download for $institutionType (e.g. "hospital_facility"), never the
 * corresponding *_speciality download -- hospital/clinic/dental each have
 * two independent MhlwDatasetDownload rows per import run (one per
 * dataset_key in config/mhlw.php), and passing the wrong one here would
 * silently mark every active facility of that type as closed. This class
 * does not and cannot validate that itself; enforcing it is the calling
 * job's responsibility (Phase 4).
 *
 * Uses chunkById() (not cursor()) deliberately: this reconciler writes to
 * the same table it's reading from mid-iteration, which is unsafe with an
 * unbuffered cursor() query on the same connection (MySQL "commands out
 * of sync") but safe with chunkById()'s buffered, re-queried pages.
 */
final class FacilityClosureReconciler
{
    private const int CHUNK_SIZE = 500;

    /**
     * @return Collection<int, MedicalFacility>
     */
    public function reconcile(InstitutionType $institutionType, MhlwDatasetDownload $facilityDownload): Collection
    {
        $closed = collect();

        MedicalFacility::query()
            ->where('institution_type', $institutionType)
            ->where('status', MedicalFacilityStatus::Active)
            ->where(function ($query) use ($facilityDownload) {
                $query->whereNull('last_seen_mhlw_dataset_download_id')
                    ->orWhere('last_seen_mhlw_dataset_download_id', '!=', $facilityDownload->id);
            })
            ->chunkById(self::CHUNK_SIZE, function (Collection $facilities) use ($facilityDownload, $closed) {
                foreach ($facilities as $facility) {
                    DB::transaction(function () use ($facility, $facilityDownload) {
                        $facility->status = MedicalFacilityStatus::Closed;
                        $facility->save();

                        MedicalFacilityEvent::create([
                            'medical_facility_id' => $facility->id,
                            'department_code' => null,
                            'event_type' => MedicalFacilityEventType::Removed,
                            'occurred_on' => $facilityDownload->published_on,
                            'payload' => ['name' => $facility->name, 'address' => $facility->address],
                            'mhlw_dataset_download_id' => $facilityDownload->id,
                        ]);
                    });

                    $closed->push($facility);
                }
            });

        return $closed;
    }
}
