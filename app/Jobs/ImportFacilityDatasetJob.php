<?php

namespace App\Jobs;

use App\Models\MhlwDatasetDownload;
use App\Services\Mhlw\Import\FacilityDatasetRowSource;
use App\Services\Mhlw\Sync\FacilityClosureReconciler;
use App\Services\Mhlw\Sync\FacilityUpserter;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Imports one facility-role dataset (hospital_facility, clinic_facility,
 * dental_facility, maternity_home, or pharmacy) end to end: streams its
 * latest downloaded CSV, upserts every row, then runs closure detection for
 * that institution type. Row-level failures are caught and logged so one
 * malformed row doesn't sacrifice the rest of a 100k+ row file; structural
 * failures (missing download, unreadable zip, a reconciler error) are left
 * to bubble out and fail the job, since Laravel's failed_jobs table already
 * captures those.
 *
 * When this dataset has a paired speciality dataset (hospital/clinic/dental),
 * the caller MUST chain the corresponding ImportSpecialityDatasetJob after
 * this one -- DepartmentClosureReconciler requires this job's facility
 * closures to already be committed. See ImportMhlwDatasets.
 */
class ImportFacilityDatasetJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct(
        public readonly string $datasetKey,
    ) {}

    public function handle(
        FacilityDatasetRowSource $rowSource,
        FacilityUpserter $upserter,
        FacilityClosureReconciler $reconciler,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $download = MhlwDatasetDownload::latestFor($this->datasetKey);

        if ($download === null) {
            Log::warning('mhlw:import: no download recorded, skipping.', ['dataset_key' => $this->datasetKey]);

            return;
        }

        $institutionType = config("mhlw.datasets.{$this->datasetKey}.institution_type");
        $zipPath = Storage::disk('local')->path($download->local_path);

        $counts = array_fill_keys(['created', 'reopened', 'updated', 'unchanged', 'skipped'], 0);

        foreach ($rowSource->rows($this->datasetKey, $zipPath) as $mapped) {
            try {
                $result = $upserter->upsert($mapped, $download);
                $counts[strtolower($result['outcome']->name)]++;
            } catch (Throwable $e) {
                $counts['skipped']++;
                Log::error('mhlw:import: failed to upsert facility row', [
                    'dataset_key' => $this->datasetKey,
                    'source_id' => $mapped['source_id'] ?? null,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $reconciler->reconcile($institutionType, $download);

        Log::info('mhlw:import: facility dataset finished', ['dataset_key' => $this->datasetKey, ...$counts]);
    }
}
