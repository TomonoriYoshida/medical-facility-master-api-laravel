<?php

namespace App\Console\Commands;

use App\Services\Http\OpenDataHttp;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * Alerts when php.net has a newer release of the PHP branch this container
 * runs, and again once the base image (config/php_update.php) is out with
 * it, so a security release is noticed and deployed with
 * `docker compose build --pull`. Each of the two is alerted once, however
 * often this runs; a failed lookup is only a warning, so an outage of
 * php.net or Docker Hub does not page anyone.
 */
#[Signature('php:check-update')]
#[Description('Alert when a newer PHP release of the running branch, or a base image built with it, is available')]
class CheckPhpUpdate extends Command
{
    private const string NOTIFIED_CACHE_KEY = 'php-update:notified';

    public function __construct(
        private readonly OpenDataHttp $http,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $branch = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        try {
            $release = $this->latestRelease($branch);

            if (! version_compare($release['version'], PHP_VERSION, '>')) {
                $this->components->info('PHP '.PHP_VERSION." は {$branch} 系の最新です。");

                return Command::SUCCESS;
            }

            $imageTag = str_replace('{version}', $release['version'], config()->string('php_update.image_tag'));
            $imageAvailable = $this->imageExists($imageTag);
        } catch (ConnectionException|RequestException|UnexpectedValueException $e) {
            Log::warning("php:check-update: 最新の PHP を確認できませんでした: {$e->getMessage()}");
            $this->components->warn("最新の PHP を確認できませんでした: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $message = $imageAvailable
            ? "PHP {$release['version']} のイメージ（{$imageTag}）が出ました。いま動いているのは ".PHP_VERSION.' です。docker compose build --pull でデプロイしてください。'
            : "PHP {$release['version']} が出ました（{$release['date']}）。いま動いているのは ".PHP_VERSION." です。イメージ（{$imageTag}）はまだありません。";

        $this->components->warn($message);

        $state = $release['version'].($imageAvailable ? ':image' : '');

        if (Cache::get(self::NOTIFIED_CACHE_KEY) !== $state) {
            Log::error("php:check-update: {$message}", [
                'security' => $release['security'],
                'releases' => 'https://www.php.net/ChangeLog-'.PHP_MAJOR_VERSION.'.php',
            ]);
            Cache::forever(self::NOTIFIED_CACHE_KEY, $state);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{version: string, date: string, security: bool}
     */
    private function latestRelease(string $branch): array
    {
        $url = str_replace('{branch}', $branch, config()->string('php_update.releases_url'));
        $response = $this->http->get($url, timeout: 20);
        $version = $response->json('version');

        if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new UnexpectedValueException("{$url} の応答に PHP の版がありません。");
        }

        /** @var list<string> $tags */
        $tags = (array) $response->json('tags', []);

        return [
            'version' => $version,
            'date' => (string) $response->json('date', ''),
            'security' => in_array('security', $tags, true),
        ];
    }

    private function imageExists(string $tag): bool
    {
        $url = str_replace(
            ['{repository}', '{tag}'],
            [config()->string('php_update.image_repository'), $tag],
            config()->string('php_update.image_tag_url'),
        );

        try {
            $this->http->get($url, timeout: 20);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }
}
