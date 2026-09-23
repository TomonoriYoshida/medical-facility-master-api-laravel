<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Services\Rhb\Download\KantoShinetsuLinkResolver;
use PHPUnit\Framework\TestCase;

class KantoShinetsuLinkResolverTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../../../Fixtures/rhb-kantoshinetsu-index.html');
    }

    public function test_it_resolves_exactly_the_three_all_prefecture_zip_links(): void
    {
        $links = (new KantoShinetsuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertCount(3, $links);

        $byCategory = [];
        foreach ($links as $link) {
            $byCategory[$link->category->name] = $link;
        }

        $this->assertSame('https://kouseikyoku.mhlw.go.jp/kantoshinetsu/shitei_ika_r0809.zip', $byCategory['Medical']->url);
        $this->assertSame('shitei_shika_r0809.zip', $byCategory['Dental']->filename);
        $this->assertSame('shitei_yakkyoku_r0809.zip', $byCategory['Pharmacy']->filename);
    }

    public function test_the_heisetsu_subset_links_are_excluded(): void
    {
        // Regression test: 医科（歯科併設）/歯科（医科併設） links share the
        // "ika"/"shika" substring (e.g. "shitei_ikaheisetsu_r0809.zip"
        // contains "ika") but are filtered subsets of facilities already
        // present in the plain 医科/歯科 files, so they must never be
        // resolved as their own links.
        $links = (new KantoShinetsuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $filenames = array_map(fn ($link) => $link->filename, $links);

        $this->assertNotContains('shitei_shikaheisetsu_r0809.zip', $filenames);
        $this->assertNotContains('shitei_ikaheisetsu_r0809.zip', $filenames);
    }

    public function test_the_published_on_date_is_parsed_from_the_reiwa_era_paragraph(): void
    {
        $links = (new KantoShinetsuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertSame('2026-09-01', $links[0]->publishedOn->toDateString());
    }
}
