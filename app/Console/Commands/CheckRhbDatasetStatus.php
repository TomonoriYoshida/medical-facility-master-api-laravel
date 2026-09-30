<?php

namespace App\Console\Commands;

use App\Models\RhbDatasetDownload;
use App\Services\Rhb\RhbScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Checks that every bureau/category's current data made it all the way into
 * medical_facilities, as a single daily verdict for monitoring: rhb:import
 * only enqueues jobs, so its own exit code says nothing about whether the
 * import succeeded. Flags a pair with no download at all, a current
 * download not yet imported (a failed or still-pending import job), or a
 * latest published date older than rhb.stale_after_days (the bureau stopped
 * publishing, or its page changed so the latest version is no longer found).
 */
#[Signature('rhb:status')]
#[Description('Check that every regional health bureau (地方厚生局) dataset is downloaded, imported and up to date')]
class CheckRhbDatasetStatus extends Command
{
    public function handle(RhbScope $scope): int
    {
        $staleAfterDays = config()->integer('rhb.stale_after_days');

        $rows = [];
        $problemCount = 0;
        $problemSummaries = [];

        foreach ($scope->bureaus() as $meta) {
            foreach ($scope->categories() as $category) {
                $downloads = RhbDatasetDownload::allFor($meta['bureau'], $category);
                $publishedOn = $downloads->first()?->published_on;

                $problems = [];

                if ($publishedOn === null) {
                    $problems[] = '未取得';
                } else {
                    if ($downloads->contains(fn (RhbDatasetDownload $download): bool => $download->imported_at === null)) {
                        $problems[] = '未取込';
                    }

                    $ageInDays = (int) $publishedOn->diffInDays(today());

                    if ($ageInDays > $staleAfterDays) {
                        $problems[] = "{$ageInDays}日間更新なし";
                    }
                }

                $problemCount += count($problems);

                if ($problems !== []) {
                    $problemSummaries[] = "{$meta['label']} {$category->name}: ".implode(' / ', $problems);
                }

                $rows[] = [
                    $meta['label'],
                    $category->name,
                    $publishedOn?->toDateString() ?? '-',
                    $problems === [] ? '<fg=green>OK</>' : '<fg=red>'.implode(' / ', $problems).'</>',
                ];
            }
        }

        $this->table(['局', 'カテゴリ', '公開日', '状態'], $rows);

        if ($problemCount > 0) {
            $this->components->error("{$problemCount}件の問題があります。");

            // The scheduler discards this table, so the log is where the
            // reason behind a failed healthcheck ping can be found.
            Log::error("rhb:status: {$problemCount}件の問題があります。", ['problems' => $problemSummaries]);

            return Command::FAILURE;
        }

        $this->components->info('すべてのデータセットが取込済みで最新です。');

        return Command::SUCCESS;
    }
}
