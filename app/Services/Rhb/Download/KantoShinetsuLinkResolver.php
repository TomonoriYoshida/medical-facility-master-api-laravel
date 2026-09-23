<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Resolves download links from 関東信越厚生局's index page. Verified
 * against the real page: a "令和8年9月1日現在" publication-date label
 * (wrapped in a plain <p>, unlike Hokkaido's 【】 or Tohoku's <h3>) is
 * followed by a per-prefecture PDF table and a "各都県分　エクセルデータ"
 * row whose 3 ZIP files (医科/歯科/薬局, one zip per category, one .xlsx
 * per prefecture inside each -- see KantoShinetsuBundleExpander) are what
 * this resolver targets.
 *
 * As with Tohoku, two extra ZIPs -- 医科（歯科併設） and 歯科（医科併設） --
 * are published alongside the 3 wanted ones; filenames follow
 * "shitei_{ika,shika,yakkyoku}_r{YYMM}.zip" for the wanted ones and
 * "shitei_{shikaheisetsu,ikaheisetsu}_r{YYMM}.zip" for the co-located
 * subsets, so requiring the category slug to be followed immediately by
 * "_r" excludes the heisetsu variants without needing to inspect link
 * text or table position (verified against the real page's full href
 * list, same technique as TohokuLinkResolver).
 */
final class KantoShinetsuLinkResolver implements BureauLinkResolverInterface
{
    /**
     * @var array<string, RhbCategory>
     */
    private const array CATEGORY_SLUGS = [
        'ika' => RhbCategory::Medical,
        'shika' => RhbCategory::Dental,
        'yakkyoku' => RhbCategory::Pharmacy,
    ];

    public function __construct(
        private readonly IndexPageEraDateParser $eraDateParser = new IndexPageEraDateParser,
    ) {}

    public function resolve(string $html, string $baseUrl): array
    {
        $publishedOn = $this->extractPublishedOn($html);

        $links = [];

        foreach (self::CATEGORY_SLUGS as $slug => $category) {
            if (! preg_match('/href="([^"]*shitei_'.$slug.'_r\d+\.zip)"/', $html, $matches)) {
                continue;
            }

            $links[] = new ResolvedRhbDatasetLink(
                category: $category,
                url: rtrim($baseUrl, '/').'/'.ltrim($matches[1], '/'),
                filename: basename($matches[1]),
                publishedOn: $publishedOn,
            );
        }

        return $links;
    }

    private function extractPublishedOn(string $html): CarbonImmutable
    {
        if (! preg_match('/<p>'.IndexPageEraDateParser::PATTERN.'<\/p>/u', $html, $matches)) {
            throw new RuntimeException('Unable to find the "current as of" date on the Kanto-Shinetsu index page.');
        }

        return $this->eraDateParser->parse($matches[1], $matches[2], (int) $matches[3], (int) $matches[4]);
    }
}
