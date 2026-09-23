<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Resolves download links from 九州厚生局's index page. Verified against
 * the real page: unlike every other bureau, the page is a 12-month
 * archive -- one "現在" date label followed by one <table> per month, all
 * sharing the same structure. Only the FIRST (most recent) table is
 * wanted; the rest are history, so both the date and the row-scan are
 * scoped to the first "<table" through its matching "</table>".
 *
 * That table has 8 <td> cells, one per prefectural office (福岡/佐賀/
 * 長崎/熊本/大分/宮崎/鹿児島/沖縄), each holding 3 per-category PDF links
 * plus exactly one "エクセルデータ（ZIP）" link that bundles ALL THREE
 * categories for that one prefecture -- the opposite bundling axis from
 * every other bureau (which bundles multiple prefectures into one
 * per-category zip). The 沖縄 cell has a real-data defect: its office-name
 * paragraph is accidentally wrapped in an unrelated .zip link, so cells
 * are matched by link TEXT ("エクセルデータ"), not merely by .zip
 * extension, to avoid picking that stray link up.
 *
 * Since RhbDatasetDownload/ImportRhbFacilityListJob assume one category
 * per download row, each of the 8 resolved office-zips is expanded here
 * into 3 ResolvedRhbDatasetLinks (Medical/Dental/Pharmacy) sharing the
 * same url/filename -- rhb:download will fetch and store the same zip 3
 * times (once per category-tagged row), and KyushuBundleExpander picks
 * out only the one category's file from within it. This keeps the
 * Import/Sync/Job/command layers untouched, at the cost of redundant
 * downloads.
 */
final class KyushuLinkResolver implements BureauLinkResolverInterface
{
    public function __construct(
        private readonly IndexPageEraDateParser $eraDateParser = new IndexPageEraDateParser,
    ) {}

    public function resolve(string $html, string $baseUrl): array
    {
        // The date label precedes the <table> tag itself, so it must be
        // read from the full (unscoped) html -- taking the first match is
        // still correct since each month's own label immediately precedes
        // its own table, in the same document order as the tables.
        $publishedOn = $this->extractPublishedOn($html);
        $scopedHtml = $this->scopeToLatestTable($html);

        $links = [];

        preg_match_all('/<td\b.*?<\/td>/su', $scopedHtml, $cellMatches);

        foreach ($cellMatches[0] as $cellHtml) {
            if (! preg_match('/<a[^>]+href="([^"]+\.zip)"[^>]*>エクセルデータ/u', $cellHtml, $hrefMatch)) {
                continue;
            }

            $url = rtrim($baseUrl, '/').'/'.ltrim($hrefMatch[1], '/');
            $filename = basename($hrefMatch[1]);

            foreach (RhbCategory::cases() as $category) {
                $links[] = new ResolvedRhbDatasetLink(
                    category: $category,
                    url: $url,
                    filename: $filename,
                    publishedOn: $publishedOn,
                );
            }
        }

        return $links;
    }

    private function scopeToLatestTable(string $html): string
    {
        $tableStartPos = strpos($html, '<table');

        if ($tableStartPos === false) {
            throw new RuntimeException('Unable to find any table on the Kyushu index page.');
        }

        $tableEndPos = strpos($html, '</table>', $tableStartPos);

        if ($tableEndPos === false) {
            throw new RuntimeException('Unable to find the closing </table> for the Kyushu facility list table.');
        }

        return substr($html, $tableStartPos, $tableEndPos - $tableStartPos);
    }

    private function extractPublishedOn(string $html): CarbonImmutable
    {
        if (! preg_match('/<strong>'.IndexPageEraDateParser::PATTERN.'<\/strong>/u', $html, $matches)) {
            throw new RuntimeException('Unable to find the "current as of" date on the Kyushu index page.');
        }

        return $this->eraDateParser->parse($matches[1], $matches[2], (int) $matches[3], (int) $matches[4]);
    }
}
