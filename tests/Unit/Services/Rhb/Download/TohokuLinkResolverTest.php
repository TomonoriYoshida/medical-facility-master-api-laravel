<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Services\Rhb\Download\TohokuLinkResolver;
use PHPUnit\Framework\TestCase;

class TohokuLinkResolverTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../../../Fixtures/rhb-tohoku-index.html');
    }

    public function test_it_resolves_exactly_the_three_six_prefecture_combined_links(): void
    {
        $links = (new TohokuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertCount(3, $links);

        $byCategory = [];
        foreach ($links as $link) {
            $byCategory[$link->category->name] = $link;
        }

        $this->assertSame('https://kouseikyoku.mhlw.go.jp/tohoku/shitei-touhoku-ika-r0809.xlsx', $byCategory['Medical']->url);
        $this->assertSame('shitei-touhoku-shika-r0809.xlsx', $byCategory['Dental']->filename);
        $this->assertSame('shitei-touhoku-yakkyoku-r0809.xlsx', $byCategory['Pharmacy']->filename);
    }

    public function test_the_heisetsu_subset_links_are_excluded(): void
    {
        // Regression test: 歯科併設/医科併設 links share the "ika"/"shika"
        // prefix (e.g. "shitei-touhoku-shikaheisetsu-r0809.xlsx" contains
        // "shika") but are filtered subsets of facilities already present
        // in the plain 医科/歯科 files (verified against real data), so
        // they must never be resolved as their own links.
        $links = (new TohokuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $filenames = array_map(fn ($link) => $link->filename, $links);

        $this->assertNotContains('shitei-touhoku-shikaheisetsu-r0809.xlsx', $filenames);
        $this->assertNotContains('shitei-touhoku-ikaheisetsu-r0809.xlsx', $filenames);
    }

    public function test_the_changelog_tables_no_category_files_are_not_mistaken_for_a_category_link(): void
    {
        // The second table's "6県分" row links (shitei-touhoku-r0808.xlsx,
        // no category suffix) must not be picked up as if "touhoku-r0808"
        // somehow matched a category pattern.
        $links = (new TohokuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $filenames = array_map(fn ($link) => $link->filename, $links);

        $this->assertNotContains('shitei-touhoku-r0808.xlsx', $filenames);
    }

    public function test_the_published_on_date_is_parsed_from_the_reiwa_era_heading(): void
    {
        // The changelog table's own "令和8年8月処理分" heading uses "処理分"
        // not "現在", so it must not be mistaken for the full-list date.
        $links = (new TohokuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertSame('2026-09-01', $links[0]->publishedOn->toDateString());
    }
}
