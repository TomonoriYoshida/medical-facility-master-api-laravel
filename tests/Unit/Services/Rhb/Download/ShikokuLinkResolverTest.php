<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Services\Rhb\Download\ShikokuLinkResolver;
use PHPUnit\Framework\TestCase;

class ShikokuLinkResolverTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../../../Fixtures/rhb-shikoku-index.html');
    }

    public function test_it_resolves_exactly_the_three_all_prefecture_zip_links(): void
    {
        $links = (new ShikokuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertCount(3, $links);

        $byCategory = [];
        foreach ($links as $link) {
            $byCategory[$link->category->name] = $link;
        }

        $this->assertSame('https://kouseikyoku.mhlw.go.jp/shikoku/000499483.zip', $byCategory['Medical']->url);
        $this->assertSame('000499485.zip', $byCategory['Dental']->filename);
        $this->assertSame('000499487.zip', $byCategory['Pharmacy']->filename);
    }

    public function test_the_facility_standards_table_zip_links_are_not_mistaken_for_the_facility_list_links(): void
    {
        // Regression test: the page has a second table ("届出受理医療機関
        // 名簿", facility-standards acceptance status) with the exact same
        // 医科/歯科/薬局 row structure and its own ZIP links -- a plain
        // page-wide row-scan would pick those up too. Scoping to the
        // "コード内容別医療機関一覧表" table only must exclude them.
        $links = (new ShikokuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $filenames = array_map(fn ($link) => $link->filename, $links);

        $this->assertNotContains('000499505.zip', $filenames);
        $this->assertNotContains('000499506.zip', $filenames);
        $this->assertNotContains('000499507.zip', $filenames);
    }

    public function test_the_published_on_date_is_parsed_despite_the_mismatched_half_width_closing_paren(): void
    {
        // Regression test: real data shows the wanted table's date label
        // uses a full-width opening paren but a half-width closing paren
        // ("（令和8年9月1日現在)"), unlike the facility-standards table's
        // consistently full-width "（...）".
        $links = (new ShikokuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertSame('2026-09-01', $links[0]->publishedOn->toDateString());
    }
}
