<?php

namespace App\Services\Abr;

use App\Enums\AbrDataset;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Downloads Address Base Registry files into the local disk and reads them
 * back as CSV rows. Files are kept between runs and revalidated with their
 * ETag, so an unchanged file (most of them, most days) is not downloaded
 * again. The files are large (地番 alone is ~3GB nationwide), which is why
 * rows are streamed rather than loaded.
 */
final class AbrDatasetClient
{
    private const string DIRECTORY = 'abr';

    /** @var array<string, string|null> paths already revalidated during this process */
    private array $revalidated = [];

    /**
     * Local path of a dataset file, downloading it if missing or changed.
     * Null when the registry has no such file (地番 is not published for
     * every municipality).
     *
     * @param  string  $area  see AbrDataset::path()
     */
    public function fetch(AbrDataset $dataset, string $area): ?string
    {
        $path = $dataset->path($area);

        if (array_key_exists($path, $this->revalidated)) {
            return $this->revalidated[$path];
        }

        return $this->revalidated[$path] = $this->download($path);
    }

    /**
     * The rows of a downloaded file as column => value arrays.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(string $localPath): Generator
    {
        $zip = new ZipArchive;

        if ($zip->open($localPath) !== true) {
            throw new RuntimeException("Unable to open ABR zip \"{$localPath}\".");
        }

        try {
            $stream = $zip->getStream((string) $zip->getNameIndex(0));

            if ($stream === false) {
                throw new RuntimeException("Unable to read the CSV in ABR zip \"{$localPath}\".");
            }

            $header = fgetcsv($stream, escape: '');

            if ($header === false) {
                return;
            }

            $header = array_map(fn (?string $column): string => (string) $column, $header);
            $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $columnCount = count($header);

            while (($line = fgetcsv($stream, escape: '')) !== false) {
                if (count($line) !== $columnCount) {
                    continue;
                }

                yield array_combine($header, array_map(fn (?string $value): string => (string) $value, $line));
            }

            fclose($stream);
        } finally {
            $zip->close();
        }
    }

    private function download(string $path): ?string
    {
        $disk = Storage::disk('local');
        $localPath = self::DIRECTORY.'/'.$path;
        $etagPath = $localPath.'.etag';
        $temporaryPath = $localPath.'.download';
        $etag = $disk->exists($localPath) && $disk->exists($etagPath) ? trim((string) $disk->get($etagPath)) : null;

        $disk->makeDirectory(dirname($localPath));

        try {
            $response = Http::withHeaders($etag !== null ? ['If-None-Match' => $etag] : [])
                ->timeout(300)
                // A 304 is neither successful nor failed, so the callback gets null for it.
                ->retry(3, 1000, fn (?Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
                ->sink($disk->path($temporaryPath))
                ->get(rtrim(config()->string('abr.base_url'), '/').'/'.$path);
        } catch (Throwable $exception) {
            $disk->delete($temporaryPath);

            throw $exception;
        }

        if ($response->status() === 304) {
            $disk->delete($temporaryPath);

            return $disk->path($localPath);
        }

        if ($response->status() === 404) {
            $disk->delete([$temporaryPath, $localPath, $etagPath]);

            return null;
        }

        if (! $response->successful()) {
            $disk->delete($temporaryPath);

            throw new RuntimeException("Downloading ABR file \"{$path}\" failed with HTTP {$response->status()}.");
        }

        $disk->move($temporaryPath, $localPath);
        $disk->put($etagPath, (string) $response->header('ETag'));

        return $disk->path($localPath);
    }
}
