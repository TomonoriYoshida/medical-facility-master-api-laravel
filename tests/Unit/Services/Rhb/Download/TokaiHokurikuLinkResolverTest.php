<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Services\Rhb\Download\TokaiHokurikuLinkResolver;
use PHPUnit\Framework\TestCase;

class TokaiHokurikuLinkResolverTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../../../Fixtures/rhb-tokaihokuriku-index.html');
    }

    public function test_it_resolves_exactly_the_three_all_prefecture_zip_links(): void
    {
        $links = (new TokaiHokurikuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertCount(3, $links);

        $byCategory = [];
        foreach ($links as $link) {
            $byCategory[$link->category->name] = $link;
        }

        $this->assertSame('https://kouseikyoku.mhlw.go.jp/tokaihokuriku/2609-01-01.zip', $byCategory['Medical']->url);
        $this->assertSame('2609-01-03.zip', $byCategory['Dental']->filename);
        $this->assertSame('2609-01-04.zip', $byCategory['Pharmacy']->filename);
    }

    public function test_per_prefecture_pdf_links_are_not_mistaken_for_the_all_prefecture_zip_links(): void
    {
        // Regression test: the per-prefecture PDF links' text embeds the
        // prefecture name alongside the category (e.g. "（富山医科）"),
        // which must not be mistaken for the plain "（医科）" label that
        // marks the all-prefecture ZIP link, and their .pdf extension
        // must exclude them regardless.
        $links = (new TokaiHokurikuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $filenames = array_map(fn ($link) => $link->filename, $links);

        $this->assertNotContains('2609-01-16-01.pdf', $filenames);
        $this->assertNotContains('2609-01-24-01.pdf', $filenames);
    }

    public function test_the_published_on_date_is_parsed_from_the_reiwa_era_list_item(): void
    {
        $links = (new TokaiHokurikuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertSame('2026-09-01', $links[0]->publishedOn->toDateString());
    }
}
