<?php

namespace App\Console\Commands;

use App\Enums\InstitutionType;
use App\Enums\MhlwDatasetRole;
use App\Jobs\ImportFacilityDatasetJob;
use App\Jobs\ImportSpecialityDatasetJob;
use Illuminate\Bus\Batch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;

/**
 * Dispatches the queued import pipeline (ImportFacilityDatasetJob /
 * ImportSpecialityDatasetJob) for the latest downloaded MHLW datasets.
 * Fire-and-forget by default: this only enqueues work onto
 * QUEUE_CONNECTION, a separately-running worker (`sail artisan queue:work`,
 * or the queue:listen process `sail composer run dev` already starts)
 * performs the actual import.
 */
#[Signature('mhlw:import
    {--dataset=* : 対象データセットキーを絞り込む（指定なしは全件、繰り返し指定可）}
    {--wait : バッチが完了するまで待機し、結果を表示する（ローカル動作確認用）}')]
#[Description('Import the latest downloaded MHLW datasets into medical_facilities / medical_facility_departments')]
class ImportMhlwDatasets extends Command
{
    public function handle(): int
    {
        $datasets = config('mhlw.datasets');

        /** @var list<string> $requested */
        $requested = $this->option('dataset');

        if ($requested !== []) {
            $unknown = array_diff($requested, array_keys($datasets));

            if ($unknown !== []) {
                $this->components->error('未知のデータセットキーです: '.implode(', ', $unknown));

                return Command::FAILURE;
            }

            $datasets = Arr::only($datasets, $requested);

            if (! $this->specialityKeysArePaired($datasets)) {
                $this->components->error('診療科目データセットを指定する場合は、対応する施設データセットも同時に指定してください。');

                return Command::FAILURE;
            }
        }

        $jobs = $this->buildJobs($datasets);

        if ($jobs === []) {
            $this->components->warn('対象データセットがありません。');

            return Command::FAILURE;
        }

        $batch = Bus::batch($jobs)
            ->name('mhlw:import '.now()->toDateTimeString())
            ->allowFailures()
            ->dispatch();

        $this->components->info("インポートをキューに投入しました (batch ID: {$batch->id})。");
        $this->components->warn('キューワーカーが起動していないと処理は進みません（`sail artisan queue:work` または `sail composer run dev`）。');

        if (! $this->option('wait')) {
            return Command::SUCCESS;
        }

        return $this->waitForBatch($batch);
    }

    /**
     * Groups facility-role dataset keys with their paired speciality-role
     * dataset key (if selected) into a chain, so Bus::batch() runs the
     * facility import and commits its closure detection before the
     * speciality import starts -- DepartmentClosureReconciler depends on
     * that order. Datasets with no speciality pair (maternity_home,
     * pharmacy) become standalone jobs.
     *
     * @param  array<string, array{label: string, slug: string, institution_type: InstitutionType, role: MhlwDatasetRole}>  $datasets
     * @return list<object|list<object>>
     */
    private function buildJobs(array $datasets): array
    {
        $byInstitutionType = [];

        foreach ($datasets as $key => $meta) {
            $byInstitutionType[$meta['institution_type']->value][$meta['role']->value][] = $key;
        }

        $jobs = [];

        foreach ($byInstitutionType as $roles) {
            foreach ($roles[MhlwDatasetRole::Facility->value] ?? [] as $facilityKey) {
                $specialityKeys = $roles[MhlwDatasetRole::Speciality->value] ?? [];

                $jobs[] = $specialityKeys === []
                    ? new ImportFacilityDatasetJob($facilityKey)
                    : [new ImportFacilityDatasetJob($facilityKey), new ImportSpecialityDatasetJob($specialityKeys[0])];
            }
        }

        return $jobs;
    }

    /**
     * @param  array<string, array{label: string, slug: string, institution_type: InstitutionType, role: MhlwDatasetRole}>  $datasets
     */
    private function specialityKeysArePaired(array $datasets): bool
    {
        foreach ($datasets as $meta) {
            if ($meta['role'] !== MhlwDatasetRole::Speciality) {
                continue;
            }

            $hasFacilityPair = collect($datasets)->contains(
                fn (array $other): bool => $other['institution_type'] === $meta['institution_type']
                    && $other['role'] === MhlwDatasetRole::Facility
            );

            if (! $hasFacilityPair) {
                return false;
            }
        }

        return true;
    }

    private function waitForBatch(Batch $batch): int
    {
        while (! $batch->finished()) {
            if (! app()->environment('testing')) {
                usleep(500_000);
            }

            $batch = $batch->fresh();
        }

        $this->components->twoColumnDetail('成功', (string) ($batch->totalJobs - $batch->failedJobs));
        $this->components->twoColumnDetail('失敗', (string) $batch->failedJobs);

        return $batch->failedJobs > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
