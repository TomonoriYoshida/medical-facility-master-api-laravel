<?php

namespace Tests\Unit\Services\AccessLog;

use App\Services\AccessLog\AccessLogSummary;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AccessLogSummaryTest extends TestCase
{
    private string $directory;

    private CarbonImmutable $from;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/access-log-summary-'.uniqid();
        mkdir($this->directory);
        $this->from = CarbonImmutable::parse('2026-09-30 00:00', 'Asia/Tokyo');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->directory}/*") ?: []);
        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_only_requests_within_the_period_are_counted(): void
    {
        $this->writeLog('access.log', [
            $this->entry(at: '2026-09-29 23:59:59'),
            $this->entry(at: '2026-09-30 00:00:00'),
            $this->entry(at: '2026-09-30 23:59:59'),
            $this->entry(at: '2026-10-01 00:00:00'),
        ]);

        $this->assertSame(2, $this->summarize()->total);
    }

    public function test_rotated_gzipped_files_are_read_with_the_current_file(): void
    {
        $this->writeLog('access-2026-09-30T06-00-00.000-interval.log.gz', [$this->entry(), $this->entry()], gzip: true);
        $this->writeLog('access.log', [$this->entry()]);

        $summary = $this->summarize();

        $this->assertSame(2, $summary->filesRead);
        $this->assertSame(3, $summary->total);
    }

    public function test_a_rotated_file_last_written_before_the_period_is_not_read(): void
    {
        $this->writeLog('access-2026-09-28T06-00-00.000-interval.log.gz', [$this->entry()], gzip: true);
        touch("{$this->directory}/access-2026-09-28T06-00-00.000-interval.log.gz", $this->from->subSecond()->getTimestamp());

        $this->assertSame(0, $this->summarize()->filesRead);
    }

    public function test_lines_that_are_not_access_log_entries_are_ignored(): void
    {
        $this->writeLog('access.log', ['not json', '{"level":"info","msg":"no status"}', $this->entry()]);

        $this->assertSame(1, $this->summarize()->total);
    }

    public function test_requests_are_counted_by_status_ip_and_not_found_path(): void
    {
        $this->writeLog('access.log', [
            $this->entry(ip: '198.51.100.1', status: 429),
            $this->entry(ip: '198.51.100.1', status: 429),
            $this->entry(ip: '198.51.100.1', status: 200),
            $this->entry(ip: '203.0.113.9', status: 404, uri: '/wp-login.php?redirect=1'),
            $this->entry(ip: '203.0.113.9', status: 404, uri: '/wp-login.php'),
            $this->entry(ip: '203.0.113.9', status: 404, uri: '/.env'),
            $this->entry(ip: '192.0.2.5', status: 503),
        ]);

        $summary = $this->summarize();

        $this->assertSame(2, $summary->count(429));
        $this->assertSame(3, $summary->count(404));
        $this->assertSame(1, $summary->countServerErrors());
        $this->assertSame([
            '198.51.100.1' => ['requests' => 3, 'rate_limited' => 2],
            '203.0.113.9' => ['requests' => 3, 'rate_limited' => 0],
        ], $summary->topIps(2));
        $this->assertSame(['/wp-login.php' => 2, '/.env' => 1], $summary->topNotFoundPaths(10));
        $this->assertSame(['198.51.100.1' => 3, '203.0.113.9' => 3], $summary->ipsSendingAtLeast(3));
    }

    public function test_the_client_ip_is_preferred_over_the_connecting_ip(): void
    {
        $this->writeLog('access.log', [
            json_encode(['ts' => $this->from->addHour()->getTimestamp(), 'status' => 200, 'request' => ['remote_ip' => '10.0.0.1', 'client_ip' => '198.51.100.1', 'uri' => '/']]),
        ]);

        $this->assertSame(['198.51.100.1'], array_keys($this->summarize()->topIps(10)));
    }

    private function summarize(): AccessLogSummary
    {
        return AccessLogSummary::read("{$this->directory}/access.log", $this->from, $this->from->addDay());
    }

    /**
     * @param  list<string|false>  $lines
     */
    private function writeLog(string $name, array $lines, bool $gzip = false): void
    {
        $contents = implode("\n", $lines)."\n";

        file_put_contents("{$this->directory}/{$name}", $gzip ? gzencode($contents) : $contents);
    }

    private function entry(string $ip = '198.51.100.1', int $status = 200, string $uri = '/api/v1/options', string $at = '2026-09-30 12:00:00'): string
    {
        return (string) json_encode([
            'level' => 'info',
            'ts' => CarbonImmutable::parse($at, 'Asia/Tokyo')->getTimestamp() + 0.5,
            'msg' => 'handled request',
            'request' => ['remote_ip' => $ip, 'client_ip' => $ip, 'method' => 'GET', 'uri' => $uri],
            'status' => $status,
        ]);
    }
}
