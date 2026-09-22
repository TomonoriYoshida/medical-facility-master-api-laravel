<?php

namespace App\Console\Commands;

use App\Models\MhlwDatasetDownload;
use App\Services\Mhlw\MhlwDatasetLinkResolver;
use App\Services\Mhlw\ResolvedMhlwDatasetLink;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[Signature('mhlw:download')]
#[Description('Download the latest MHLW medical facility datasets when a newer version is available')]
class DownloadMhlwDatasets extends Command
{
    private const string USER_AGENT = 'MedicalFacilityMasterAPI/1.0 (+https://github.com/TomonoriYoshida/medical-facility-master-api-laravel)';

    public function handle(MhlwDatasetLinkResolver $resolver): int
    {
        try {
            $html = $this->fetch(config('mhlw.index_url'))->throw()->body();
        } catch (Throwable $e) {
            $this->components->error("インデックスページの取得に失敗しました: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $hasFailure = false;

        foreach (config('mhlw.datasets') as $key => $dataset) {
            if (! $this->processDataset($resolver, $html, $key, $dataset['label'], $dataset['slug'])) {
                $hasFailure = true;
            }

            if (! app()->environment('testing')) {
                usleep(200_000);
            }
        }

        if ($hasFailure) {
            $this->components->warn('一部のデータセットでエラーが発生しました。');
        } else {
            $this->components->info('すべてのデータセットを確認しました。');
        }

        return $hasFailure ? Command::FAILURE : Command::SUCCESS;
    }

    private function processDataset(
        MhlwDatasetLinkResolver $resolver,
        string $html,
        string $key,
        string $label,
        string $slug,
    ): bool {
        try {
            $latest = $resolver->resolveLatest($html, $slug, config('mhlw.base_url'));

            if ($latest === null) {
                $this->components->warn("{$label}: 最新リンクが見つかりませんでした");

                return false;
            }

            $alreadyDownloaded = MhlwDatasetDownload::query()
                ->where('dataset_key', $key)
                ->where('filename', $latest->filename)
                ->exists();

            if ($alreadyDownloaded) {
                $this->components->twoColumnDetail($label, '<fg=gray>最新版を取得済み</>');

                return true;
            }

            $this->download($key, $label, $latest);

            return true;
        } catch (Throwable $e) {
            $this->components->error("{$label}: ダウンロードに失敗しました ({$e->getMessage()})");

            return false;
        }
    }

    private function download(string $key, string $label, ResolvedMhlwDatasetLink $latest): void
    {
        $body = $this->fetch($latest->url)->throw()->body();

        if (! str_starts_with($body, "PK\x03\x04")) {
            throw new RuntimeException('レスポンスがzip形式ではありません');
        }

        $localPath = "mhlw/{$key}/{$latest->filename}";

        Storage::disk('local')->put($localPath, $body);

        MhlwDatasetDownload::create([
            'dataset_key' => $key,
            'filename' => $latest->filename,
            'published_on' => $latest->publishedOn,
            'source_url' => $latest->url,
            'local_path' => $localPath,
            'downloaded_at' => now(),
        ]);

        $this->components->twoColumnDetail($label, "<fg=green>ダウンロード完了 ({$latest->filename})</>");
    }

    private function fetch(string $url): Response
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(3, 1000, fn (Throwable $exception): bool => $exception instanceof ConnectionException)
            ->get($url);
    }
}
