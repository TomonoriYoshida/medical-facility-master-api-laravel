<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Resolves download links from 東北厚生局's index page. Verified against
 * the real page: it has two separate tables -- a "コード内容別医療機関
 * 一覧表" (the current full list, what we want) and a "保険医療機関・保険
 * 薬局の新規指定・廃止・辞退・取消一覧" (a monthly delta/changelog list,
 * out of scope for this snapshot-diff pipeline). The full-list table has a
 * per-prefecture row for each of the 6 Tohoku prefectures plus a "6県分"
 * row whose 3 files (医科/歯科/薬局, one workbook per category, one sheet
 * per prefecture inside each) are what this resolver targets -- the
 * per-prefecture individual files are intentionally not used (would mean
 * 6x as many downloads for no benefit, since the combined files already
 * carry every prefecture as separate sheets).
 *
 * The "6県分" row also publishes two extra files -- 歯科併設 (dental
 * co-located) and 医科併設 (medical co-located) -- which were confirmed
 * against real data to be filtered *subsets* of facilities already present
 * in the 医科/歯科 files (same facility codes, same addresses), not new
 * facilities; they are deliberately not resolved here.
 *
 * Link filenames follow "shitei-touhoku-{category}-r{YYMM}.xlsx", where
 * {category} is one of ika(医科)/shika(歯科)/yakkyoku(薬局). The
 * heisetsu variants use "shitei-touhoku-{ika,shika}heisetsu-r{YYMM}.xlsx"
 * -- requiring the category slug to be followed immediately by "-r"
 * excludes them without needing to inspect link text or table position at
 * all (verified against the real page's full href list).
 */
final class TohokuLinkResolver implements BureauLinkResolverInterface
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
            if (! preg_match('/href="([^"]*shitei-touhoku-'.$slug.'-r\d+\.xlsx)"/', $html, $matches)) {
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
        if (! preg_match('/<h3[^>]*>'.IndexPageEraDateParser::PATTERN.'<\/h3>/u', $html, $matches)) {
            throw new RuntimeException('Unable to find the "current as of" date on the Tohoku index page.');
        }

        return $this->eraDateParser->parse($matches[1], $matches[2], (int) $matches[3], (int) $matches[4]);
    }
}
