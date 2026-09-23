<?php

namespace App\Console\Commands;

use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Download\ResolvedRhbDatasetLink;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[Signature('rhb:download
    {--bureau=* : 対象の局キーを絞り込む（config/rhb.phpのキー、指定なしは全局、繰り返し指定可）}')]
#[Description('Download the latest regional health bureau (地方厚生局) medical institution lists when a newer version is available')]
class DownloadRhbDatasets extends Command
{
    private const string USER_AGENT = 'MedicalFacilityMasterAPI/1.0 (+https://github.com/TomonoriYoshida/medical-facility-master-api-laravel)';

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

        $hasFailure = false;

        foreach ($bureaus as $bureauKey => $meta) {
            if (! $this->processBureau($bureauKey, $meta)) {
                $hasFailure = true;
            }
        }

        if ($hasFailure) {
            $this->components->warn('一部の局でエラーが発生しました。');
        } else {
            $this->components->info('すべての局を確認しました。');
        }

        return $hasFailure ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function processBureau(string $bureauKey, array $meta): bool
    {
        try {
            $html = $this->fetch($meta['index_url'])->throw()->body();
        } catch (Throwable $e) {
            $this->components->error("{$meta['label']}: インデックスページの取得に失敗しました ({$e->getMessage()})");

            return false;
        }

        $resolver = app($meta['resolver']);
        $links = $resolver->resolve($html, $meta['base_url']);

        $hasFailure = false;

        foreach ($links as $link) {
            if (! $this->processLink($bureauKey, $meta, $link)) {
                $hasFailure = true;
            }

            if (! app()->environment('testing')) {
                usleep(200_000);
            }
        }

        return ! $hasFailure;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function processLink(string $bureauKey, array $meta, ResolvedRhbDatasetLink $link): bool
    {
        $label = "{$meta['label']} {$link->category->name}";

        try {
            // Some bureaus (Hokkaido confirmed) never change a document's
            // filename/URL between monthly updates -- the page's own
            // "current as of" date is the only signal that content
            // changed, so filename alone cannot be the dedup key or every
            // update after the first would be silently skipped forever.
            $alreadyDownloaded = RhbDatasetDownload::query()
                ->where('bureau_code', $meta['bureau'])
                ->where('category', $link->category)
                ->where('filename', $link->filename)
                ->where('published_on', $link->publishedOn->toDateString())
                ->exists();

            if ($alreadyDownloaded) {
                $this->components->twoColumnDetail($label, '<fg=gray>最新版を取得済み</>');

                return true;
            }

            $this->download($bureauKey, $meta, $link);

            return true;
        } catch (Throwable $e) {
            $this->components->error("{$label}: ダウンロードに失敗しました ({$e->getMessage()})");

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function download(string $bureauKey, array $meta, ResolvedRhbDatasetLink $link): void
    {
        $body = $this->fetch($link->url)->throw()->body();

        if (! str_starts_with($body, "PK\x03\x04")) {
            throw new RuntimeException('レスポンスがExcel(xlsx)形式ではありません');
        }

        $categoryKey = strtolower($link->category->name);
        $localPath = "rhb/{$bureauKey}/{$categoryKey}/{$link->filename}";

        Storage::disk('local')->put($localPath, $body);

        RhbDatasetDownload::create([
            'bureau_code' => $meta['bureau'],
            'category' => $link->category,
            'prefecture_codes' => $meta['prefecture_codes'],
            'filename' => $link->filename,
            'source_url' => $link->url,
            'local_path' => $localPath,
            'published_on' => $link->publishedOn,
            'downloaded_at' => now(),
        ]);

        $this->components->twoColumnDetail(
            "{$meta['label']} {$link->category->name}",
            "<fg=green>ダウンロード完了 ({$link->filename})</>",
        );
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
