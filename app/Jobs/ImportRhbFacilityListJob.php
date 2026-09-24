<?php

namespace App\Jobs;

use App\Enums\InstitutionType;
use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Download\BundleExpanderInterface;
use App\Services\Rhb\Import\InsuredFacilityDatasetRowSource;
use App\Services\Rhb\Sync\FacilityClosureReconciler;
use App\Services\Rhb\Sync\FacilityUpserter;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Imports every currently-known download for one (bureau, category) pair
 * end to end: expands each download into its file units (a bureau may
 * publish more than one file per category -- Hokkaido publishes hospital
 * and clinic data as two separate files, both RhbCategory::Medical),
 * upserts every row, then runs closure reconciliation once per
 * (institution type, prefecture) combination actually encountered.
 *
 * Unlike the old MHLW pipeline, there is only one job type: this data
 * source has no separate "speciality" dataset requiring a chained
 * facility-then-department job pair, so no chain/ordering dependency
 * exists between different (bureau, category) jobs -- they are fully
 * independent and can run in any order or in parallel.
 */
class ImportRhbFacilityListJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    // Real Kanto-Shinetsu Medical data (36,451 rows across 10 prefecture
    // files, all upserted within one job run) measured at ~227 rows/sec,
    // needing ~160s -- well past the worker's default 60s kill. 600s
    // leaves headroom for larger bureaus still to come (e.g. Kinki).
    public int $timeout = 600;

    public function __construct(
        public readonly RhbBureau $bureau,
        public readonly RhbCategory $category,
    ) {}

    public function handle(
        InsuredFacilityDatasetRowSource $rowSource,
        FacilityUpserter $upserter,
        FacilityClosureReconciler $reconciler,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $downloads = RhbDatasetDownload::allFor($this->bureau, $this->category);

        if ($downloads->isEmpty()) {
            Log::warning('rhb:import: no download recorded, skipping.', [
                'bureau' => $this->bureau->name,
                'category' => $this->category->name,
            ]);

            return;
        }

        $expander = $this->resolveExpander();

        $counts = array_fill_keys(['created', 'reopened', 'updated', 'unchanged', 'skipped'], 0);

        /** @var array<string, array{institutionType: InstitutionType, prefectureCode: string, download: RhbDatasetDownload}> $reconcileTargets */
        $reconcileTargets = [];

        // A row whose upsert failed is still present in the dataset, but
        // its last_seen_rhb_dataset_download_id was never advanced -- left
        // alone, reconciliation would wrongly close it as "disappeared".
        /** @var array<string, list<string>> $failedFacilityCodes */
        $failedFacilityCodes = [];

        foreach ($downloads as $download) {
            foreach ($expander->expand($download) as $unit) {
                foreach ($rowSource->rows($unit->xlsxPath, $unit->category, $unit->bureau, $unit->prefectureCode, $unit->sheetName) as $mapped) {
                    $key = "{$mapped['institution_type']->value}:{$unit->prefectureCode}";

                    try {
                        $result = $upserter->upsert($mapped, $download);
                        $counts[strtolower($result['outcome']->name)]++;

                        $reconcileTargets[$key] = [
                            'institutionType' => $mapped['institution_type'],
                            'prefectureCode' => $unit->prefectureCode,
                            'download' => $download,
                        ];
                    } catch (Throwable $e) {
                        $counts['skipped']++;
                        $failedFacilityCodes[$key][] = $mapped['facility_code'];

                        Log::error('rhb:import: failed to upsert facility row', [
                            'bureau' => $this->bureau->name,
                            'category' => $this->category->name,
                            'facility_code' => $mapped['facility_code'] ?? null,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        foreach ($reconcileTargets as $key => $target) {
            $reconciler->reconcile(
                $target['institutionType'],
                $target['prefectureCode'],
                $target['download'],
                excludedFacilityCodes: $failedFacilityCodes[$key] ?? [],
            );
        }

        Log::info('rhb:import: dataset finished', [
            'bureau' => $this->bureau->name,
            'category' => $this->category->name,
            ...$counts,
        ]);
    }

    private function resolveExpander(): BundleExpanderInterface
    {
        foreach (config('rhb.bureaus') as $meta) {
            if ($meta['bureau'] === $this->bureau) {
                return app($meta['expander']);
            }
        }

        throw new RuntimeException("No configuration found for bureau \"{$this->bureau->name}\".");
    }
}
