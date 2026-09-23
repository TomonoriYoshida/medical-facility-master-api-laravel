<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Resolves download links from 近畿厚生局's index page. Verified against
 * the real page: a "令和8年9月1日現在" publication-date label (wrapped in
 * `<em>...</em>`) precedes the "保険医療機関・保険薬局の指定一覧（全体）"
 * section's table, which has exactly 3 all-prefecture ZIP links
 * (医科/歯科/薬局, one zip per category, one .xlsx per prefecture inside
 * each, with 併設 (co-located) files mixed in -- see KinkiBundleExpander).
 *
 * The page also lists per-prefecture PDF links (whose filenames embed the
 * prefecture name between "kikanzentai_" and the category, e.g.
 * "kikanzentai_fukui_ika.pdf") and a separate "新規指定一覧" (new
 * designations only, a monthly changelog akin to Tohoku's "処理分" table)
 * section with its own ZIP links that don't contain "kikanzentai" at all.
 * Requiring "kikanzentai_" to be followed immediately by the category slug
 * cleanly excludes both (verified against the real page's full href list,
 * only 3 matches).
 */
final class KinkiLinkResolver implements BureauLinkResolverInterface
{
    /**
     * @var array<string, RhbCategory>
     */
    private const array CATEGORY_SLUGS = [
        'ika' => RhbCategory::Medical,
        'sika' => RhbCategory::Dental,
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
            if (! preg_match('/href="([^"]*kikanzentai_'.$slug.'\.zip)"/', $html, $matches)) {
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
        if (! preg_match('/<em>'.IndexPageEraDateParser::PATTERN.'<\/em>/u', $html, $matches)) {
            throw new RuntimeException('Unable to find the "current as of" date on the Kinki index page.');
        }

        return $this->eraDateParser->parse($matches[1], $matches[2], (int) $matches[3], (int) $matches[4]);
    }
}
