<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Resolves download links from 中国四国厚生局's index page. Verified
 * against the real page: a "（令和8年9月1日現在）" publication-date label
 * (full-width parens, embedded directly in the section's own <h2>)
 * precedes the "保険医療機関・保険薬局のコード内容別医療機関一覧" table,
 * whose 3 rows (医科/歯科/薬局) each hold 5 per-prefecture PDF links plus
 * one all-prefecture ZIP link in a trailing "各県分エクセルデータ" column.
 *
 * Unlike Tokai-Hokuriku, the ZIP filenames are opaque numeric codes
 * ("000500018.zip") with no category text in the anchor's own link text
 * either -- but each row's cells all repeat the row's category as a
 * `<strong>` label (e.g. every PDF and the ZIP link in the 医科 row
 * contain "<strong>医科</strong>" verbatim), so resolving per <tr> and
 * matching that label -- the same technique HokkaidoLinkResolver uses --
 * identifies each row's wanted .zip link. 4 further changelog sections
 * ("新規指定一覧"/"廃止一覧"/"辞退一覧"/"指定取消一覧") appear later on the
 * page, but none of their rows carry a 医科/歯科/薬局 label, so they are
 * naturally excluded (verified against the real page's full row list). A
 * later "指定訪問看護事業所" section happens to repeat the exact same date
 * string in its own heading, but since it appears after the target
 * section, taking the first match in document order still resolves the
 * correct date.
 */
final class ChugokuShikokuLinkResolver implements BureauLinkResolverInterface
{
    /**
     * @var array<string, RhbCategory>
     */
    private const array CATEGORY_LABELS = [
        '医科' => RhbCategory::Medical,
        '歯科' => RhbCategory::Dental,
        '薬局' => RhbCategory::Pharmacy,
    ];

    public function __construct(
        private readonly IndexPageEraDateParser $eraDateParser = new IndexPageEraDateParser,
    ) {}

    public function resolve(string $html, string $baseUrl): array
    {
        $publishedOn = $this->extractPublishedOn($html);

        $links = [];

        preg_match_all('/<tr>(.*?)<\/tr>/su', $html, $rowMatches);

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

    private function extractPublishedOn(string $html): CarbonImmutable
    {
        if (! preg_match('/（'.IndexPageEraDateParser::PATTERN.'）/u', $html, $matches)) {
            throw new RuntimeException('Unable to find the "current as of" date on the Chugoku-Shikoku index page.');
        }

        return $this->eraDateParser->parse($matches[1], $matches[2], (int) $matches[3], (int) $matches[4]);
    }
}
