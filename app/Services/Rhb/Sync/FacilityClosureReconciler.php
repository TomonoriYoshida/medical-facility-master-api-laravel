<?php

namespace App\Services\Rhb\Sync;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use App\Models\RhbDatasetDownload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds every currently-Active-or-Suspended facility of one institution
 * type and prefecture that a given import run's download did NOT touch
 * (its last_seen_rhb_dataset_download_id is stale or null), marks it
 * Closed, and records a Removed event for each.
 *
 * $prefectureCode scopes the query in addition to the stale-download
 * check: a single rhb_dataset_downloads row can bundle multiple
 * prefectures (e.g. Tohoku's one file covering 6 prefectures), so
 * checking last_seen_rhb_dataset_download_id alone would be correct for
 * this run's own prefectures but could not, by itself, prove a given
 * prefecture was actually supposed to appear in this specific run.
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
    public function reconcile(InstitutionType $institutionType, string $prefectureCode, RhbDatasetDownload $download): Collection
    {
        $closed = collect();

        MedicalFacility::query()
            ->where('institution_type', $institutionType)
            ->where('prefecture_code', $prefectureCode)
            ->whereIn('status', [MedicalFacilityStatus::Active, MedicalFacilityStatus::Suspended])
            ->where(function ($query) use ($download) {
                $query->whereNull('last_seen_rhb_dataset_download_id')
                    ->orWhere('last_seen_rhb_dataset_download_id', '!=', $download->id);
            })
            ->chunkById(self::CHUNK_SIZE, function (Collection $facilities) use ($download, $closed) {
                foreach ($facilities as $facility) {
                    DB::transaction(function () use ($facility, $download) {
                        $facility->status = MedicalFacilityStatus::Closed;
                        $facility->save();

                        MedicalFacilityEvent::create([
                            'medical_facility_id' => $facility->id,
                            'event_type' => MedicalFacilityEventType::Removed,
                            'occurred_on' => $download->published_on,
                            'payload' => ['name' => $facility->name, 'address' => $facility->address],
                            'rhb_dataset_download_id' => $download->id,
                        ]);
                    });

                    $closed->push($facility);
                }
            });

        return $closed;
    }
}
