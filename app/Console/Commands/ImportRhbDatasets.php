<?php

namespace App\Console\Commands;

use App\Enums\RhbCategory;
use App\Jobs\ImportRhbFacilityListJob;
use Illuminate\Bus\Batch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;

/**
 * Dispatches the queued import pipeline (ImportRhbFacilityListJob) for the
 * latest downloaded regional health bureau (地方厚生局) datasets.
 * Fire-and-forget by default: this only enqueues work onto
 * QUEUE_CONNECTION, a separately-running worker (`sail artisan
 * queue:work`, or the queue:listen process `sail composer run dev`
 * already starts) performs the actual import.
 *
 * Unlike the old MHLW pipeline's facility/speciality job pairs, there is
 * no chain/ordering dependency between jobs here -- every (bureau,
 * category) job is fully independent, so the batch is a flat list.
 */
#[Signature('rhb:import
    {--bureau=* : 対象の局キーを絞り込む（config/rhb.phpのキー、指定なしは全局、繰り返し指定可）}
    {--wait : バッチが完了するまで待機し、結果を表示する（ローカル動作確認用）}')]
#[Description('Import the latest downloaded regional health bureau (地方厚生局) datasets into medical_facilities')]
class ImportRhbDatasets extends Command
{
    public function handle(): int
    {
        $bureaus = config('rhb.bureaus');

        /** @var list<string> $requested */
        $requested = $this->option('bureau');

        if ($requested !== []) {
            $unknown = array_diff($requested, array_keys($bureaus));

            if ($unknown !== []) {
                $this->components->error('未知の局キーです: '.implode(', ', $unknown));

                return Command::FAILURE;
            }

            $bureaus = Arr::only($bureaus, $requested);
        }

        $jobs = [];

        foreach ($bureaus as $meta) {
            foreach (RhbCategory::cases() as $category) {
                $jobs[] = new ImportRhbFacilityListJob($meta['bureau'], $category);
            }
        }

        if ($jobs === []) {
            $this->components->warn('対象の局がありません。');

            return Command::FAILURE;
        }

        $batch = Bus::batch($jobs)
            ->name('rhb:import '.now()->toDateTimeString())
            ->allowFailures()
            ->dispatch();

        $this->components->info("インポートをキューに投入しました (batch ID: {$batch->id})。");
        $this->components->warn('キューワーカーが起動していないと処理は進みません（`sail artisan queue:work` または `sail composer run dev`）。');

        if (! $this->option('wait')) {
            return Command::SUCCESS;
        }

        return $this->waitForBatch($batch);
    }

    private function waitForBatch(Batch $batch): int
    {
        // finished() alone is not enough: Laravel never sets finished_at on
        // a batch with a failed job (it stays "pending" awaiting a retry),
        // so waiting on it would hang forever after any job failure.
        // pendingJobs === failedJobs means every job has run exactly once.
        while (! $batch->finished() && $batch->pendingJobs > $batch->failedJobs) {
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
