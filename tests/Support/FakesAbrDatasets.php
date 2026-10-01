<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Serves Address Base Registry files from Http::fake(): each file is a zip
 * holding one CSV, as the registry publishes them. Paths not given answer
 * 404, as the registry does for a 地番 file that is not published.
 */
trait FakesAbrDatasets
{
    /** @var array<string, int> path => number of requests */
    private array $abrRequests = [];

    /** @var (callable(string): void)|null called with the path of each request, before it is answered */
    private $onAbrRequest = null;

    /**
     * @param  array<string, list<array<string, string>>>  $files  path below the base URL (AbrDataset::path()) => rows as column => value
     */
    private function fakeAbr(array $files): void
    {
        $zips = array_map(fn (array $rows): string => $this->abrZip($rows), $files);

        Http::fake(function (Request $request) use ($zips) {
            $path = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');
            $this->abrRequests[$path] = ($this->abrRequests[$path] ?? 0) + 1;

            if ($this->onAbrRequest !== null) {
                ($this->onAbrRequest)($path);
            }

            if (! isset($zips[$path])) {
                return Http::response('', 404);
            }

            $etag = '"'.md5($zips[$path]).'"';

            if ($request->header('If-None-Match') === [$etag]) {
                return Http::response('', 304, ['ETag' => $etag]);
            }

            return Http::response($zips[$path], 200, ['ETag' => $etag]);
        });
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function abrZip(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false || $rows === []) {
            throw new RuntimeException('An ABR fixture needs at least one row.');
        }

        fputcsv($handle, array_keys($rows[0]), escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'abr');
        $zip = new ZipArchive;
        $zip->open((string) $path, ZipArchive::OVERWRITE);
        $zip->addFromString('data.csv', $csv);
        $zip->close();

        $contents = (string) file_get_contents((string) $path);
        unlink((string) $path);

        return $contents;
    }

    /**
     * @return array<string, string>
     */
    private function abrTown(string $lgCode, string $machiazaId, string $oaza, string $chome = '', string $koaza = '', bool $residential = false): array
    {
        return [
            'lg_code' => $lgCode, 'machiaza_id' => $machiazaId, 'oaza_cho' => $oaza,
            'chome_number' => $chome, 'koaza' => $koaza, 'rsdt_addr_flg' => $residential ? '1' : '0', 'ablt_date' => '',
        ];
    }

    /**
     * @param  array<string, string>  $keys
     * @return array<string, string>
     */
    private function abrPosition(array $keys, float $latitude, float $longitude): array
    {
        return [...$keys, 'rep_lon' => (string) $longitude, 'rep_lat' => (string) $latitude];
    }
}
