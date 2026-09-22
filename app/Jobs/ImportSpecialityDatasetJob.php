<?php

namespace App\Jobs;

use App\Models\MedicalFacility;
use App\Models\MhlwDatasetDownload;
use App\Services\Mhlw\Import\SpecialityDatasetRowSource;
use App\Services\Mhlw\Sync\DepartmentClosureReconciler;
use App\Services\Mhlw\Sync\DepartmentUpserter;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Imports one speciality-role dataset (hospital_speciality,
 * clinic_speciality, or dental_speciality) end to end: streams its latest
 * downloaded CSV, resolves each group's owning MedicalFacility by source_id
 * (DepartmentUpserter requires an already-resolved model), upserts every
 * department, then runs closure detection for that institution type.
 *
 * MUST run after -- and be chained behind -- the paired
 * ImportFacilityDatasetJob for the same institution type: its facility
 * closures must already be committed before DepartmentClosureReconciler
 * runs, or departments of facilities closed in the same run would each get
 * their own spurious Removed event. See ImportMhlwDatasets.
 */
class ImportSpecialityDatasetJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct(
        public readonly string $datasetKey,
    ) {}

    public function handle(
        SpecialityDatasetRowSource $rowSource,
        DepartmentUpserter $upserter,
        DepartmentClosureReconciler $reconciler,
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

        $facility = null;
        $counts = array_fill_keys(['created', 'updated', 'unchanged', 'skipped'], 0);

        foreach ($rowSource->rows($zipPath) as $mapped) {
            if ($facility === null || $facility->source_id !== $mapped['source_id']) {
                $facility = MedicalFacility::where('source_id', $mapped['source_id'])->first();
            }

            if ($facility === null) {
                $counts['skipped']++;
                Log::warning('mhlw:import: department group references unknown source_id, skipping.', [
                    'dataset_key' => $this->datasetKey,
                    'source_id' => $mapped['source_id'],
                    'department_code' => $mapped['department_code'],
                ]);

                continue;
            }

            try {
                $result = $upserter->upsert($mapped, $facility, $download);
                $counts[strtolower($result['outcome']->name)]++;
            } catch (Throwable $e) {
                $counts['skipped']++;
                Log::error('mhlw:import: failed to upsert department group', [
                    'dataset_key' => $this->datasetKey,
                    'source_id' => $mapped['source_id'],
                    'department_code' => $mapped['department_code'],
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $reconciler->reconcile($institutionType, $download);

        Log::info('mhlw:import: speciality dataset finished', ['dataset_key' => $this->datasetKey, ...$counts]);
    }
}
