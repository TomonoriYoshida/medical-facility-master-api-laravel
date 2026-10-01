<?php

namespace App\Services\Population;

use App\Services\Http\OpenDataHttp;
use App\Services\Rhb\Import\RhbXlsxReader;
use Generator;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Finds the latest 住民基本台帳人口 file by municipality on the 総務省 index
 * page, downloads it and reads each municipality's population.
 */
final class MunicipalityPopulationClient
{
    private const string DIRECTORY = 'population';

    /** 令和1年 = 2019. */
    private const int REIWA_OFFSET = 2018;

    public function __construct(
        private readonly OpenDataHttp $http,
    ) {}

    /**
     * The latest file of the whole population (【総計】, not 日本人住民 or
     * 外国人住民) by municipality, and the January 1 it is as of. Its link
     * reads e.g. 「【総計】令和8年住民基本台帳人口・世帯数、令和7年人口動態（市区町村別）」;
     * the age-group file next to it (年齢階級別) is not it. Earlier editions
     * may stay listed on the page, in any order, so the years are compared.
     *
     * @return array{as_of: Carbon, url: string}
     */
    public function latest(): array
    {
        $indexUrl = config()->string('population.index_url');
        $html = $this->utf8($this->http->get($indexUrl)->body());
        $latest = null;

        // Only .xlsx: RhbXlsxReader cannot read the older binary .xls format.
        preg_match_all('#<a[^>]+href="([^"]+\.xlsx)"[^>]*>(.*?)</a>#siu', $html, $links, PREG_SET_ORDER);

        foreach ($links as [, $href, $label]) {
            $text = trim(strip_tags($label));

            if (! str_contains($text, '【総計】') || ! str_contains($text, '人口・世帯数') || ! str_contains($text, '市区町村別')
                || str_contains($text, '年齢') || preg_match('/令和([0-9０-９]+|元)年/u', $text, $year) !== 1) {
                continue;
            }

            // Government pages often write the year in full-width digits (令和８年).
            $reiwa = $year[1] === '元' ? 1 : (int) mb_convert_kana($year[1], 'n');

            if ($latest === null || $reiwa > $latest['reiwa']) {
                $latest = ['reiwa' => $reiwa, 'href' => html_entity_decode($href)];
            }
        }

        if ($latest === null) {
            throw new RuntimeException("No 住民基本台帳人口 file by municipality was found on {$indexUrl}.");
        }

        return [
            'as_of' => Carbon::parse((self::REIWA_OFFSET + $latest['reiwa']).'-01-01'),
            'url' => $this->resolveUrl($latest['href'], $indexUrl),
        ];
    }

    /**
     * Downloads a file into the local disk and returns its path.
     */
    public function download(string $url): string
    {
        return $this->http->download($url, self::DIRECTORY, timeout: 120);
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
        $headerRows = [];

        foreach ((new RhbXlsxReader($xlsxPath))->rows() as $row) {
            if ($populationColumn === null) {
                $populationColumn = $this->populationColumn($row, $headerRows);
                $headerRows[] = $row;

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
     * The header: a row reading 計 under 人口 in some row above it (repeated
     * per column, or once over 男 / 女 / 計 if merged, so look leftwards).
     * Every 計 is tried, since other groups such as 世帯数 may have their own.
     *
     * @param  array<int, string>  $row
     * @param  list<array<int, string>>  $rowsAbove
     */
    private function populationColumn(array $row, array $rowsAbove): ?int
    {
        foreach (array_keys($row, '計', true) as $column) {
            foreach ($rowsAbove as $above) {
                if ($this->nearestToTheLeft($above, $column) === '人口') {
                    return $column;
                }
            }
        }

        return null;
    }

    /**
     * Resolves a link on the index page against the page's own URL.
     */
    private function resolveUrl(string $href, string $pageUrl): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $scheme = (string) parse_url($pageUrl, PHP_URL_SCHEME);
        $origin = $scheme.'://'.parse_url($pageUrl, PHP_URL_HOST);

        if (str_starts_with($href, '//')) {
            return "{$scheme}:{$href}";
        }

        $path = str_starts_with($href, '/')
            ? $href
            : preg_replace('#[^/]*$#', '', (string) parse_url($pageUrl, PHP_URL_PATH)).$href;
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            match ($segment) {
                '..' => array_pop($segments),
                '.' => null,
                default => $segments[] = $segment,
            };
        }

        return $origin.'/'.ltrim(implode('/', $segments), '/');
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

    /**
     * The 総務省 pages are Shift_JIS (Windows-31J).
     */
    private function utf8(string $html): string
    {
        return mb_check_encoding($html, 'UTF-8') ? $html : (string) mb_convert_encoding($html, 'UTF-8', 'SJIS-win');
    }
}
