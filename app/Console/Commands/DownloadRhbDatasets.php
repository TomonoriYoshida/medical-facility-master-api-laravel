<?php

namespace App\Console\Commands;

use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Download\ResolvedRhbDatasetLink;
use App\Services\Rhb\RhbScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[Signature('rhb:download
    {--bureau=* : 対象の局キーを絞り込む（config/rhb.phpのキー、指定なしは対象範囲内の全局、繰り返し指定可）}')]
#[Description('Download the latest regional health bureau (地方厚生局) medical institution lists when a newer version is available')]
class DownloadRhbDatasets extends Command
{
    private const string USER_AGENT = 'MedicalFacilityMasterAPI/1.0 (+https://github.com/TomonoriYoshida/medical-facility-master-api-laravel)';

    private RhbScope $scope;

    public function handle(RhbScope $scope): int
    {
        $this->scope = $scope;
        $bureaus = $scope->bureaus();

        /** @var list<string> $requested */
        $requested = $this->option('bureau');

        if ($requested !== []) {
            $unknown = array_diff($requested, array_keys(config()->array('rhb.bureaus')));

            if ($unknown !== []) {
                $this->components->error('未知の局キーです: '.implode(', ', $unknown));

                return Command::FAILURE;
            }

            $outOfScope = array_diff($requested, array_keys($bureaus));

            if ($outOfScope !== []) {
                $this->components->error('対象範囲（RHB_PREFECTURES）外の局です: '.implode(', ', $outOfScope));

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
            $this->reportFailure("{$meta['label']}: インデックスページの取得に失敗しました", $e);

            return false;
        }

        // Resolvers throw when a bureau restructures its index page. Caught
        // here so one bureau's page change fails only that bureau instead of
        // aborting the run before the remaining bureaus are checked.
        try {
            $links = app($meta['resolver'])->resolve($html, $meta['base_url']);
        } catch (Throwable $e) {
            $this->reportFailure("{$meta['label']}: 一覧ページの解析に失敗しました", $e);

            return false;
        }

        $hasFailure = false;

        // A page change can also silently drop one category's link while the
        // rest still resolve; left unreported, that category would just stop
        // updating.
        $resolvedCategories = array_map(fn (ResolvedRhbDatasetLink $link): RhbCategory => $link->category, $links);

        foreach ($this->scope->categories() as $category) {
            if (! in_array($category, $resolvedCategories, true)) {
                $this->reportFailure("{$meta['label']}: {$category->name} のリンクが見つかりません");
                $hasFailure = true;
            }
        }

        $resolvedLinks = $links;

        $links = array_filter($links, fn (ResolvedRhbDatasetLink $link): bool => $this->scope->includesCategory($link->category)
            && ($link->prefectureCode === null || $this->scope->includesPrefecture($link->prefectureCode)));

        // Category by category, so a replaced file stops being current right
        // after its replacement is stored, not only once the whole bureau
        // (several slow ZIPs) is done.
        foreach ($this->scope->categories() as $category) {
            $categoryHasFailure = false;

            foreach ($links as $link) {
                if ($link->category !== $category) {
                    continue;
                }

                if (! $this->processLink($bureauKey, $meta, $link)) {
                    $categoryHasFailure = true;
                }

                if (! app()->environment('testing')) {
                    usleep(200_000);
                }
            }

            // Until the replacement is stored, the replaced file stays current.
            if (! $categoryHasFailure && ! $this->supersedeReplacedFiles($meta, $category, $resolvedLinks)) {
                $categoryHasFailure = true;
            }

            $hasFailure = $hasFailure || $categoryHasFailure;
        }

        return ! $hasFailure;
    }

    /**
     * A bureau may replace a file without changing the page's "current as
     * of" date (関東信越厚生局 swapped "shitei_ika_r0810.zip" for
     * "shitei_ika_r0810-1.zip" on 2026-10-09). The replaced file shares
     * published_on with its replacement, so RhbDatasetDownload::allFor()
     * would treat both as current; a file of that date no longer linked from
     * the page is marked superseded instead -- but only when the page links
     * another file of that date for the same prefectures, so a per-prefecture
     * link (Kyushu) briefly missing from the page does not drop that
     * prefecture. Compared against every resolved link, not only the in-scope
     * ones, so that narrowing RHB_PREFECTURES never supersedes a file still
     * on the page.
     *
     * @param  array<string, mixed>  $meta
     * @param  list<ResolvedRhbDatasetLink>  $resolvedLinks
     */
    private function supersedeReplacedFiles(array $meta, RhbCategory $category, array $resolvedLinks): bool
    {
        $label = "{$meta['label']} {$category->name}";

        /** @var array<string, array{filenames: list<string>, prefectures: list<string>}> $linkedByDate */
        $linkedByDate = [];

        foreach ($resolvedLinks as $link) {
            if ($link->category !== $category) {
                continue;
            }

            $date = $link->publishedOn->toDateString();
            $linkedByDate[$date]['filenames'][] = $link->filename;
            $linkedByDate[$date]['prefectures'][] = $this->prefectureKey($link->prefectureCode !== null ? [$link->prefectureCode] : $meta['prefecture_codes']);
        }

        try {
            foreach ($linkedByDate as $publishedOn => $linked) {
                $replaced = RhbDatasetDownload::query()
                    ->where('bureau_code', $meta['bureau'])
                    ->where('category', $category)
                    ->where('published_on', $publishedOn)
                    ->whereNotIn('filename', $linked['filenames'])
                    ->whereNull('superseded_at')
                    ->get()
                    ->filter(fn (RhbDatasetDownload $download): bool => in_array($this->prefectureKey($download->prefecture_codes), $linked['prefectures'], true));

                if ($replaced->isEmpty()) {
                    continue;
                }

                RhbDatasetDownload::query()->whereKey($replaced->modelKeys())->update(['superseded_at' => now()]);

                foreach ($replaced as $download) {
                    $this->components->twoColumnDetail($label, "<fg=yellow>差し替えられたため現行から除外 ({$download->filename})</>");

                    Log::info("rhb:download: {$label}: 差し替えられたファイルを現行から除外しました", [
                        'filename' => $download->filename,
                        'published_on' => $publishedOn,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $this->reportFailure("{$label}: 差し替えられたファイルの除外に失敗しました", $e);

            return false;
        }

        return true;
    }

    /**
     * @param  array<array-key, string>  $prefectureCodes
     */
    private function prefectureKey(array $prefectureCodes): string
    {
        sort($prefectureCodes);

        return implode(',', $prefectureCodes);
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
            $existing = RhbDatasetDownload::query()
                ->where('bureau_code', $meta['bureau'])
                ->where('category', $link->category)
                ->where('filename', $link->filename)
                ->where('published_on', $link->publishedOn->toDateString())
                ->first();

            if ($existing !== null) {
                // Linked from the page again after being replaced: current
                // again, and imported again, since the facilities now hold
                // its replacement's data.
                if ($existing->superseded_at !== null) {
                    $existing->update(['superseded_at' => null, 'imported_at' => null]);
                }

                $this->components->twoColumnDetail($label, '<fg=gray>最新版を取得済み</>');

                return true;
            }

            $this->download($bureauKey, $meta, $link);

            return true;
        } catch (Throwable $e) {
            $this->reportFailure("{$label}: ダウンロードに失敗しました", $e);

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

        $localPath = "rhb/{$bureauKey}/{$link->category->key()}/{$link->filename}";

        Storage::disk('local')->put($localPath, $body);

        RhbDatasetDownload::create([
            'bureau_code' => $meta['bureau'],
            'category' => $link->category,
            'prefecture_codes' => $link->prefectureCode !== null ? [$link->prefectureCode] : $meta['prefecture_codes'],
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

    /**
     * The scheduler discards a command's console output, so each failure is
     * also logged: otherwise a failed run would say nothing about which
     * bureau failed or why. The bureau stays in the message (not only in the
     * context) so the alert channel doesn't merge failures of different
     * bureaus into one notification.
     */
    private function reportFailure(string $message, ?Throwable $e = null): void
    {
        $this->components->error($e === null ? $message : "{$message} ({$e->getMessage()})");

        Log::error("rhb:download: {$message}", $e === null ? [] : ['error' => $e->getMessage()]);
    }

    private function fetch(string $url): Response
    {
        // 120s, not 30s: real Kanto-Shinetsu ZIPs run 4.7-7.4MB and the
        // source server was observed transferring at ~117KB/s, so a 30s
        // window cut off mid-download every time regardless of retries.
        return Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->connectTimeout(10)
            ->timeout(120)
            ->retry(3, 1000, fn (Throwable $exception): bool => $exception instanceof ConnectionException)
            ->get($url);
    }
}
