<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Resolves download links from 四国厚生局's index page. Verified against
 * the real page: it has TWO tables that share the exact same row
 * structure (医科/歯科/薬局 rows, each with per-prefecture PDF links plus
 * a trailing all-prefecture ZIP link) -- the wanted "コード内容別医療機関
 * 一覧表" (the full facility list) and an unrelated "届出受理医療機関名簿"
 * (facility-standards acceptance status, a different dataset). A later
 * "コード内容別訪問看護事業所一覧表" (home-visit nursing) table has the
 * same date string too. A plain row-scan across the whole page (the
 * technique ChugokuShikokuLinkResolver uses) would pick up all of them.
 *
 * The exact string "コード内容別医療機関一覧表" (no prefix/suffix) appears
 * exactly once on the page, inside the wanted table's own header cell, so
 * locating it and scoping both the date extraction and the row-scan to
 * everything up to the next "</table>" isolates the wanted table only
 * (verified against the real page's full content).
 *
 * The wanted table's own date label has a real-data quirk: opening
 * full-width paren "（" but a half-width closing ")" -- "（令和8年9月1日
 * 現在)" -- unlike every other table's consistently full-width "（...）",
 * so the date regex accepts either width on each side independently.
 */
final class ShikokuLinkResolver implements BureauLinkResolverInterface
{
    private const string TABLE_MARKER = 'コード内容別医療機関一覧表';

    /**
     * @var array<string, RhbCategory>
     */
    private const array CATEGORY_LABELS = [
        '医　科' => RhbCategory::Medical,
        '歯　科' => RhbCategory::Dental,
        '薬　局' => RhbCategory::Pharmacy,
    ];

    public function __construct(
        private readonly IndexPageEraDateParser $eraDateParser = new IndexPageEraDateParser,
    ) {}

    public function resolve(string $html, string $baseUrl): array
    {
        $scopedHtml = $this->scopeToTargetTable($html);
        $publishedOn = $this->extractPublishedOn($scopedHtml);

        $links = [];

        preg_match_all('/<tr>(.*?)<\/tr>/su', $scopedHtml, $rowMatches);

        foreach ($rowMatches[1] as $rowHtml) {
            foreach (self::CATEGORY_LABELS as $label => $category) {
                if (! str_contains($rowHtml, ">{$label}<")) {
                    continue;
                }

                if (! preg_match('/href="([^"]+\.zip)"/', $rowHtml, $hrefMatch)) {
                    break;
                }

                $links[] = new ResolvedRhbDatasetLink(
                    category: $category,
                    url: rtrim($baseUrl, '/').'/'.ltrim($hrefMatch[1], '/'),
                    filename: basename($hrefMatch[1]),
                    publishedOn: $publishedOn,
                );

                break;
            }
        }

        return $links;
    }

    private function scopeToTargetTable(string $html): string
    {
        $markerPos = strpos($html, self::TABLE_MARKER);

        if ($markerPos === false) {
            throw new RuntimeException('Unable to find the "コード内容別医療機関一覧表" table on the Shikoku index page.');
        }

        $tableEndPos = strpos($html, '</table>', $markerPos);

        if ($tableEndPos === false) {
            throw new RuntimeException('Unable to find the closing </table> for the Shikoku facility list table.');
        }

        return substr($html, $markerPos, $tableEndPos - $markerPos);
    }

    private function extractPublishedOn(string $scopedHtml): CarbonImmutable
    {
        if (! preg_match('/[（(]'.IndexPageEraDateParser::PATTERN.'[)）]/u', $scopedHtml, $matches)) {
            throw new RuntimeException('Unable to find the "current as of" date on the Shikoku index page.');
        }

        return $this->eraDateParser->parse($matches[1], $matches[2], (int) $matches[3], (int) $matches[4]);
    }
}
