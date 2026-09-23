<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Resolves download links from 東海北陸厚生局's index page. Verified
 * against the real page: a "令和8年9月1日現在" publication-date label
 * (wrapped in `<li><b>...</b></li>`, unlike any other bureau seen so far)
 * precedes an "（2）エクセルファイル" section with exactly 3 ZIP links
 * (医科/歯科/薬局, one zip per category, one .xlsx per prefecture inside
 * each -- see TokaiHokurikuBundleExpander). No 併設 (co-located) subset
 * files are published at all here, unlike Tohoku and Kanto-Shinetsu.
 *
 * Unlike other bureaus, the ZIP filenames themselves are opaque numeric
 * codes ("2609-01-01.zip") that don't encode the category, so category
 * must be read from the anchor's own link text instead. The same page
 * also lists per-prefecture PDF links whose text embeds the prefecture
 * name alongside the category (e.g. "（富山医科）"), so matching the
 * plain, un-prefixed label ("（医科）") together with a ".zip" extension
 * check cleanly picks out only the 3 wanted links (verified against the
 * real page's full anchor list).
 */
final class TokaiHokurikuLinkResolver implements BureauLinkResolverInterface
{
    /**
     * @var array<string, RhbCategory>
     */
    private const array CATEGORY_LABELS = [
        '（医科）' => RhbCategory::Medical,
        '（歯科）' => RhbCategory::Dental,
        '（薬局）' => RhbCategory::Pharmacy,
    ];

    public function __construct(
        private readonly IndexPageEraDateParser $eraDateParser = new IndexPageEraDateParser,
    ) {}

    public function resolve(string $html, string $baseUrl): array
    {
        $publishedOn = $this->extractPublishedOn($html);

        $links = [];

        preg_match_all('/<a href="([^"]+\.zip)"[^>]*>([^<]*)<\/a>/', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            [, $href, $linkText] = $match;

            foreach (self::CATEGORY_LABELS as $label => $category) {
                if (! str_contains($linkText, $label)) {
                    continue;
                }

                $links[] = new ResolvedRhbDatasetLink(
                    category: $category,
                    url: rtrim($baseUrl, '/').'/'.ltrim($href, '/'),
                    filename: basename($href),
                    publishedOn: $publishedOn,
                );

                break;
            }
        }

        return $links;
    }

    private function extractPublishedOn(string $html): CarbonImmutable
    {
        if (! preg_match('/<li><b>'.IndexPageEraDateParser::PATTERN.'<\/b><\/li>/u', $html, $matches)) {
            throw new RuntimeException('Unable to find the "current as of" date on the Tokai-Hokuriku index page.');
        }

        return $this->eraDateParser->parse($matches[1], $matches[2], (int) $matches[3], (int) $matches[4]);
    }
}
