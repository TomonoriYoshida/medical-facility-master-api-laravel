<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Services\Rhb\Download\ChugokuShikokuLinkResolver;
use PHPUnit\Framework\TestCase;

class ChugokuShikokuLinkResolverTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../../../Fixtures/rhb-chugokushikoku-index.html');
    }

    public function test_it_resolves_exactly_the_three_all_prefecture_zip_links(): void
    {
        $links = (new ChugokuShikokuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertCount(3, $links);

        $byCategory = [];
        foreach ($links as $link) {
            $byCategory[$link->category->name] = $link;
        }

        $this->assertSame('https://kouseikyoku.mhlw.go.jp/chugokushikoku/000500018.zip', $byCategory['Medical']->url);
        $this->assertSame('000500019.zip', $byCategory['Dental']->filename);
        $this->assertSame('000500020.zip', $byCategory['Pharmacy']->filename);
    }

    public function test_the_changelog_section_zip_is_not_mistaken_for_an_all_prefecture_zip_link(): void
    {
        // Regression test: the "新規指定一覧" (new designations only)
        // changelog section has its own ZIP link, but its row carries no
        // 医科/歯科/薬局 label, so the row-scan must not pick it up.
        $links = (new ChugokuShikokuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $filenames = array_map(fn ($link) => $link->filename, $links);

        $this->assertNotContains('000500028.zip', $filenames);
    }

    public function test_the_published_on_date_is_parsed_from_the_first_matching_heading_not_a_later_duplicate(): void
    {
        // Regression test: a later "指定訪問看護事業所" section repeats the
        // exact same "（令和8年9月1日現在）" date string in its own
        // heading, but the target section appears first in document
        // order, so the first match still resolves correctly.
        $links = (new ChugokuShikokuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertSame('2026-09-01', $links[0]->publishedOn->toDateString());
    }
}
