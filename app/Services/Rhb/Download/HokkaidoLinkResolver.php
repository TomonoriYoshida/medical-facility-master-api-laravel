<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Resolves download links from 北海道厚生局's index page. Verified against
 * the real page: a single "【令和8年9月1日現在】" publication-date label
 * precedes one <table> whose 4 rows are labeled 医科（病院）/医科（診療所）/
 * 歯科/薬局, each with a PDF link and an Excel (.xlsx) link. Both 医科
 * rows resolve to RhbCategory::Medical (Hokkaido publishes hospital and
 * clinic data as two separate files under the same category -- the
 * downstream InsuredFacilityRecordMapper distinguishes the institution
 * type per-record from column J, not from which file it came from).
 *
 * Note: neither the URL nor the filename embeds a date (unlike other
 * bureaus' "{slug}_{YYYYMMDD}" convention) -- update detection here can
 * only compare the page's own "current as of" date, not a filename. If
 * these document IDs turn out to stay fixed across monthly updates (not
 * verified), a filename-based dedup would under-detect new versions; this
 * is a known limitation of the pilot, not addressed here.
 */
final class HokkaidoLinkResolver implements BureauLinkResolverInterface
{
    /**
     * @var array<string, RhbCategory>
     */
    private const array CATEGORY_LABELS = [
        '医科（病院）' => RhbCategory::Medical,
        '医科（診療所）' => RhbCategory::Medical,
        '歯科' => RhbCategory::Dental,
        '薬局' => RhbCategory::Pharmacy,
    ];

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

                if (! preg_match('/href="([^"]+\.xlsx)"/', $rowHtml, $hrefMatch)) {
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
        if (! preg_match('/【(明治|大正|昭和|平成|令和)(元|\d+)年(\d+)月(\d+)日現在】/u', $html, $matches)) {
            throw new RuntimeException('Unable to find the "current as of" date on the Hokkaido index page.');
        }

        $eraStartYears = ['明治' => 1868, '大正' => 1912, '昭和' => 1926, '平成' => 1989, '令和' => 2019];
        $eraYear = $matches[2] === '元' ? 1 : (int) $matches[2];
        $year = $eraStartYears[$matches[1]] + $eraYear - 1;

        return CarbonImmutable::create($year, (int) $matches[3], (int) $matches[4]);
    }
}
