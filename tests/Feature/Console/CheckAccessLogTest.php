<?php

namespace Tests\Feature\Console;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CheckAccessLogTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/check-access-log-'.uniqid();
        mkdir($this->directory);
        config([
            'api.access_log.path' => "{$this->directory}/access.log",
            'api.access_log.alert_thresholds' => ['rate_limited' => 3, 'not_found' => 3, 'requests_per_ip' => 5],
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:15', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_ordinary_traffic_is_not_reported(): void
    {
        $this->writeLog([
            ...array_fill(0, 4, $this->entry(ip: '198.51.100.1')),
            ...array_fill(0, 2, $this->entry(ip: '198.51.100.2', status: 429)),
            ...array_fill(0, 2, $this->entry(ip: '198.51.100.3', status: 404)),
        ]);
        Log::spy();

        $this->artisan('access-log:check')
            ->expectsOutputToContain('不審なアクセスは見つかりませんでした')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('error');
    }

    public function test_many_rate_limited_responses_are_reported(): void
    {
        $this->writeLog(array_fill(0, 3, $this->entry(status: 429)));

        $this->assertReported('429（レート制限）が3件あります（しきい値 3）。');
    }

    public function test_many_not_found_responses_are_reported(): void
    {
        $this->writeLog([
            $this->entry(ip: '203.0.113.1', status: 404, uri: '/wp-login.php'),
            $this->entry(ip: '203.0.113.2', status: 404, uri: '/.env'),
            $this->entry(ip: '203.0.113.3', status: 404, uri: '/.git/config'),
        ]);

        $this->assertReported('404（見つからない）が3件あります（しきい値 3）。');
    }

    public function test_one_ip_sending_far_more_requests_than_usual_is_reported(): void
    {
        $this->writeLog(array_fill(0, 5, $this->entry(ip: '192.0.2.77')));

        $this->assertReported('192.0.2.77 から5件のリクエストがあります（しきい値 5）。');
    }

    public function test_another_day_can_be_checked_with_the_date_option(): void
    {
        $this->writeLog(array_fill(0, 3, $this->entry(status: 429, at: '2026-09-25 12:00')));

        $this->artisan('access-log:check')->assertExitCode(0);
        $this->artisan('access-log:check', ['--date' => '2026-09-25'])
            ->expectsOutputToContain('429（レート制限）が3件あります')
            ->assertExitCode(1);
    }

    public function test_an_invalid_date_is_rejected(): void
    {
        $this->artisan('access-log:check', ['--date' => '2026/09/25'])->assertExitCode(1);
        $this->artisan('access-log:check', ['--date' => '2026-02-31'])->assertExitCode(1);
    }

    public function test_a_missing_access_log_is_only_a_warning(): void
    {
        Log::spy();

        $this->artisan('access-log:check')
            ->expectsOutputToContain('アクセスログが見つかりません')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('error');
    }

    private function assertReported(string $finding): void
    {
        Log::spy();

        $this->artisan('access-log:check')
            ->expectsOutputToContain($finding)
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'access-log: 2026-09-30 に不審なアクセスの可能性があります'
                && $context['findings'] === [$finding]);
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeLog(array $lines): void
    {
        file_put_contents("{$this->directory}/access.log", implode("\n", $lines)."\n");
    }

    private function entry(string $ip = '198.51.100.1', int $status = 200, string $uri = '/api/v1/options', string $at = '2026-09-30 12:00'): string
    {
        return (string) json_encode([
            'ts' => CarbonImmutable::parse($at, 'Asia/Tokyo')->getTimestamp(),
            'request' => ['remote_ip' => $ip, 'client_ip' => $ip, 'method' => 'GET', 'uri' => $uri],
            'status' => $status,
        ]);
    }
}
