<?php

namespace App\Console\Commands;

use App\Services\AccessLog\AccessLogSummary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Summarizes one day of the access log and flags what an attack leaves
 * behind: rate-limited (429) responses, a vulnerability scanner's not-found
 * (404) responses, or one IP sending far more than any visitor. The rate
 * limit already blocks the traffic; this is so that someone notices. Run
 * with --date to investigate a past day (the log is kept 14 days).
 */
#[Signature('access-log:check
    {--date= : 集計する日（YYYY-MM-DD、日本時間）。省略時は前日}')]
#[Description('Summarize a day of the access log and alert when it looks like an attack (many 429 / 404 responses, or one IP sending far more requests than usual)')]
class CheckAccessLog extends Command
{
    private const string TIMEZONE = 'Asia/Tokyo';

    private const int TOP_LIMIT = 10;

    public function handle(): int
    {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $date = $this->parseDate($dateOption);

        if ($date === null) {
            $this->components->error('--date は YYYY-MM-DD の形式（存在する日付）で指定してください。');

            return Command::FAILURE;
        }

        $path = config()->string('api.access_log.path');
        $summary = AccessLogSummary::read($path, $date, $date->addDay());

        if ($summary->filesRead === 0) {
            $this->components->warn("アクセスログが見つかりません: {$path}");

            return Command::SUCCESS;
        }

        $day = $date->toDateString();
        $this->printSummary($day, $summary);

        Log::info("access-log: {$day} のリクエスト {$summary->total}件", [
            'rate_limited' => $summary->count(429),
            'not_found' => $summary->count(404),
            'server_errors' => $summary->countServerErrors(),
        ]);

        $findings = $this->findings($summary);

        if ($findings === []) {
            $this->components->info('不審なアクセスは見つかりませんでした。');

            return Command::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->components->error($finding);
        }

        // The scheduler discards this output, so the log (and through it
        // the alert channel) is where the finding has to go.
        Log::error("access-log: {$day} に不審なアクセスの可能性があります", [
            'findings' => $findings,
            'top_ips' => array_map(fn (array $counts): int => $counts['requests'], $summary->topIps(5)),
            'top_not_found' => $summary->topNotFoundPaths(5),
        ]);

        return Command::FAILURE;
    }

    private function parseDate(?string $dateOption): ?CarbonImmutable
    {
        if ($dateOption === null) {
            return CarbonImmutable::yesterday(self::TIMEZONE);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOption) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $dateOption, self::TIMEZONE);

        // createFromFormat() rolls 2026-02-31 over to March instead of failing.
        return $date?->toDateString() === $dateOption ? $date : null;
    }

    private function printSummary(string $day, AccessLogSummary $summary): void
    {
        $this->components->twoColumnDetail('期間', "{$day}（日本時間）");
        $this->components->twoColumnDetail('リクエスト', number_format($summary->total));
        $this->components->twoColumnDetail('429（レート制限）', number_format($summary->count(429)));
        $this->components->twoColumnDetail('404（見つからない）', number_format($summary->count(404)));
        $this->components->twoColumnDetail('5xx（サーバーエラー）', number_format($summary->countServerErrors()));

        $this->table(['IP', 'リクエスト', '429'], array_map(
            fn (string $ip, array $counts): array => [$ip, number_format($counts['requests']), number_format($counts['rate_limited'])],
            array_keys($summary->topIps(self::TOP_LIMIT)),
            $summary->topIps(self::TOP_LIMIT),
        ));

        $notFound = $summary->topNotFoundPaths(self::TOP_LIMIT);

        if ($notFound !== []) {
            $this->table(['404 のパス', '件数'], array_map(
                fn (string $path, int $count): array => [$path, number_format($count)],
                array_keys($notFound),
                $notFound,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function findings(AccessLogSummary $summary): array
    {
        $thresholds = config()->array('api.access_log.alert_thresholds');
        $findings = [];

        if ($summary->count(429) >= $thresholds['rate_limited']) {
            $findings[] = "429（レート制限）が{$summary->count(429)}件あります（しきい値 {$thresholds['rate_limited']}）。";
        }

        if ($summary->count(404) >= $thresholds['not_found']) {
            $findings[] = "404（見つからない）が{$summary->count(404)}件あります（しきい値 {$thresholds['not_found']}）。";
        }

        foreach ($summary->ipsSendingAtLeast($thresholds['requests_per_ip']) as $ip => $requests) {
            $findings[] = "{$ip} から{$requests}件のリクエストがあります（しきい値 {$thresholds['requests_per_ip']}）。";
        }

        return $findings;
    }
}
