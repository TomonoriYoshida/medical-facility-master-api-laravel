<?php

namespace App\Services\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Fetches pages and files from the government open-data sites: identifies
 * this application by its User-Agent and retries dropped connections (not
 * HTTP errors, which retrying would not fix).
 */
final class OpenDataHttp
{
    public const string USER_AGENT = 'MedicalFacilityMasterAPI/1.0 (+https://github.com/TomonoriYoshida/medical-facility-master-api-laravel)';

    /**
     * A page's response, throwing on an HTTP error.
     */
    public function get(string $url, int $timeout = 60): Response
    {
        return $this->request($timeout)->get($url)->throw();
    }

    /**
     * Downloads a file into $directory on the local disk, named after the
     * URL's last path segment, and returns its absolute path. A failed
     * download leaves no partial file behind.
     */
    public function download(string $url, string $directory, int $timeout): string
    {
        $disk = Storage::disk('local');
        $path = $directory.'/'.basename((string) parse_url($url, PHP_URL_PATH));
        $disk->makeDirectory($directory);

        try {
            $response = $this->request($timeout, throwAfterRetries: false)
                ->sink($disk->path($path))
                ->get($url);
        } catch (ConnectionException $e) {
            $disk->delete($path);

            throw $e;
        }

        if (! $response->successful()) {
            $disk->delete($path);

            throw new RuntimeException("Downloading {$url} failed with HTTP {$response->status()}.");
        }

        return $disk->path($path);
    }

    private function request(int $timeout, bool $throwAfterRetries = true): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout($timeout)
            ->retry(3, 1000, fn (?Throwable $exception): bool => $exception instanceof ConnectionException, throw: $throwAfterRetries);
    }
}
