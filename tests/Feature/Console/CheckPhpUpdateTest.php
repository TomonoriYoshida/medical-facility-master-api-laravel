<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CheckPhpUpdateTest extends TestCase
{
    private const string BRANCH = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

    private const string NEWER = self::BRANCH.'.'.(PHP_RELEASE_VERSION + 1);

    public function test_the_running_php_being_the_latest_alerts_nothing(): void
    {
        Log::spy();
        $this->fakeLookups(PHP_VERSION, imageAvailable: false);

        $this->artisan('php:check-update')
            ->expectsOutputToContain('PHP '.PHP_VERSION.' は '.self::BRANCH.' 系の最新です。')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('error');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'hub.docker.com'));
    }

    public function test_a_newer_release_without_its_image_is_alerted_once(): void
    {
        Log::spy();
        $this->fakeLookups(self::NEWER, imageAvailable: false);

        $this->artisan('php:check-update')->assertExitCode(0);
        $this->artisan('php:check-update')->assertExitCode(0);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'PHP '.self::NEWER.' が出ました（21 Oct 2026）')
                && str_contains($message, 'イメージ（1-php'.self::NEWER.'-trixie）はまだありません')
                && $context['security'] === true);
    }

    public function test_the_image_coming_out_after_the_release_is_alerted_again(): void
    {
        Log::spy();
        Http::fake([
            'www.php.net/*' => Http::response(['version' => self::NEWER, 'date' => '21 Oct 2026', 'tags' => ['security']]),
            'hub.docker.com/*' => Http::sequence()
                ->push(['message' => 'tag not found'], 404)
                ->push(['name' => '1-php'.self::NEWER.'-trixie']),
        ]);

        $this->artisan('php:check-update')->assertExitCode(0);
        $this->artisan('php:check-update')
            ->expectsOutputToContain('docker compose build --pull')
            ->assertExitCode(0);

        Log::shouldHaveReceived('error')->twice();
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, 'PHP '.self::NEWER.' のイメージ（1-php'.self::NEWER.'-trixie）が出ました'));
    }

    public function test_a_failed_lookup_is_only_a_warning(): void
    {
        Log::spy();
        Http::fake(['www.php.net/*' => Http::response('', 503)]);

        $this->artisan('php:check-update')
            ->expectsOutputToContain('最新の PHP を確認できませんでした')
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('error');
    }

    private function fakeLookups(string $latestVersion, bool $imageAvailable): void
    {
        Http::fake([
            'www.php.net/releases/index.php?json&version='.self::BRANCH => Http::response([
                'version' => $latestVersion,
                'date' => '21 Oct 2026',
                'tags' => ['security'],
            ]),
            'hub.docker.com/v2/repositories/dunglas/frankenphp/tags/1-php'.$latestVersion.'-trixie' => $imageAvailable
                ? Http::response(['name' => "1-php{$latestVersion}-trixie"])
                : Http::response(['message' => 'tag not found'], 404),
        ]);
    }
}
