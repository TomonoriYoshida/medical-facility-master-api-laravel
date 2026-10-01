<?php

namespace App\Services\Population;

use App\Services\Rhb\Import\RhbXlsxReader;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Finds the latest 住民基本台帳人口 file by municipality on the 総務省 index
 * page, downloads it and reads each municipality's population.
 */
final class MunicipalityPopulationClient
{
    private const string DIRECTORY = 'population';

    private const string USER_AGENT = 'MedicalFacilityMasterAPI/1.0 (+https://github.com/TomonoriYoshida/medical-facility-master-api-laravel)';

    /** 令和1年 = 2019. */
    private const int REIWA_OFFSET = 2018;

    /**
     * The latest file of the whole population (【総計】, not 日本人住民 or
     * 外国人住民) by municipality, and the January 1 it is as of. Its link
     * reads e.g. 「【総計】令和8年住民基本台帳人口・世帯数、令和7年人口動態（市区町村別）」;
     * the age-group file next to it (年齢階級別) is not it.
     *
     * @return array{as_of: Carbon, url: string}
     */
    public function latest(): array
    {
        $html = $this->utf8($this->get(config()->string('population.index_url')));

        preg_match_all('#<a[^>]+href="([^"]+\.xlsx?)"[^>]*>(.*?)</a>#su', $html, $links, PREG_SET_ORDER);

        foreach ($links as [, $href, $label]) {
            $text = trim(strip_tags($label));

            if (str_contains($text, '【総計】') && str_contains($text, '人口・世帯数') && str_contains($text, '市区町村別')
                && ! str_contains($text, '年齢') && preg_match('/令和(\d+|元)年/u', $text, $year) === 1) {
                $reiwa = $year[1] === '元' ? 1 : (int) $year[1];

                return [
                    'as_of' => Carbon::parse((self::REIWA_OFFSET + $reiwa).'-01-01'),
                    'url' => str_starts_with($href, 'http') ? $href : rtrim(config()->string('population.base_url'), '/').$href,
                ];
            }
        }

        throw new RuntimeException('No 住民基本台帳人口 file by municipality was found on '.config()->string('population.index_url').'.');
    }

    /**
     * Downloads a file into the local disk and returns its path.
     */
    public function download(string $url): string
    {
        $disk = Storage::disk('local');
        $path = self::DIRECTORY.'/'.basename((string) parse_url($url, PHP_URL_PATH));
        $disk->makeDirectory(self::DIRECTORY);

        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(120)
            ->retry(3, 1000, fn (?Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
            ->sink($disk->path($path))
            ->get($url);

        if (! $response->successful()) {
            $disk->delete($path);

            throw new RuntimeException("Downloading {$url} failed with HTTP {$response->status()}.");
        }

        return $disk->path($path);
    }

    /**
     * Each municipality's population (5-digit code => people). The file's
     * 6-digit 団体コード ends with a check digit; prefecture totals (xx000x)
     * and the national total are skipped. Wards of designated cities are
     * listed alongside their city, as in the municipality master.
     *
     * @return Generator<string, int>
     */
    public function populations(string $xlsxPath): Generator
    {
        $populationColumn = null;
        $previousRow = [];

        foreach ((new RhbXlsxReader($xlsxPath))->rows() as $row) {
            if ($populationColumn === null) {
                // The header: a row reading 男/女/計 under one reading 人口
                // (repeated per column, or once if merged, so look leftwards).
                $total = array_search('計', $row, true);

                if ($total !== false && $this->nearestToTheLeft($previousRow, $total) === '人口') {
                    $populationColumn = $total;
                }
                $previousRow = $row;

                continue;
            }

            $code = $row[0] ?? '';

            // A code stored as a number loses its leading zero (01–09).
            if (preg_match('/^\d{5}$/', $code) === 1) {
                $code = '0'.$code;
            }

            if (preg_match('/^\d{6}$/', $code) !== 1 || substr($code, 2, 3) === '000') {
                continue;
            }

            $population = str_replace(',', '', $row[$populationColumn] ?? '');

            if (! ctype_digit($population)) {
                throw new RuntimeException("Unreadable population \"{$population}\" for {$code} in {$xlsxPath}.");
            }

            yield substr($code, 0, 5) => (int) $population;
        }

        if ($populationColumn === null) {
            throw new RuntimeException("No 人口 / 計 column was found in {$xlsxPath}; the file's layout may have changed.");
        }
    }

    /**
     * The first non-empty cell at or left of $column.
     *
     * @param  array<int, string>  $row
     */
    private function nearestToTheLeft(array $row, int $column): ?string
    {
        for ($index = $column; $index >= 0; $index--) {
            if (($row[$index] ?? '') !== '') {
                return $row[$index];
            }
        }

        return null;
    }

    private function get(string $url): string
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(60)
            ->retry(3, 1000, fn (?Throwable $exception): bool => $exception instanceof ConnectionException)
            ->get($url)
            ->throw()
            ->body();
    }

    /**
     * The 総務省 pages are Shift_JIS (Windows-31J).
     */
    private function utf8(string $html): string
    {
        return mb_check_encoding($html, 'UTF-8') ? $html : (string) mb_convert_encoding($html, 'UTF-8', 'SJIS-win');
    }
}
