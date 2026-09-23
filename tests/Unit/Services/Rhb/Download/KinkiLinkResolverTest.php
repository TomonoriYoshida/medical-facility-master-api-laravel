<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Services\Rhb\Download\KinkiLinkResolver;
use PHPUnit\Framework\TestCase;

class KinkiLinkResolverTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../../../Fixtures/rhb-kinki-index.html');
    }

    public function test_it_resolves_exactly_the_three_all_prefecture_zip_links(): void
    {
        $links = (new KinkiLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertCount(3, $links);

        $byCategory = [];
        foreach ($links as $link) {
            $byCategory[$link->category->name] = $link;
        }

        $this->assertSame('https://kouseikyoku.mhlw.go.jp/kinki/2026.9_kikanzentai_ika.zip', $byCategory['Medical']->url);
        $this->assertSame('2026.9_kikanzentai_sika.zip', $byCategory['Dental']->filename);
        $this->assertSame('2026.9_kikanzentai_yakkyoku.zip', $byCategory['Pharmacy']->filename);
    }

    public function test_per_prefecture_pdf_links_are_not_mistaken_for_the_all_prefecture_zip_links(): void
    {
        // Regression test: per-prefecture PDF links embed the prefecture
        // name between "kikanzentai_" and the category (e.g.
        // "kikanzentai_fukui_ika.pdf"), which must not be mistaken for the
        // all-prefecture ZIP link's "kikanzentai_ika.zip" pattern, and
        // their .pdf extension excludes them regardless.
        $links = (new KinkiLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $filenames = array_map(fn ($link) => $link->filename, $links);

        $this->assertNotContains('2026.9_kikanzentai_fukui_ika.pdf', $filenames);
        $this->assertNotContains('2026.9_kikanzentai_hyogo_ika.pdf', $filenames);
    }

    public function test_the_new_designations_changelog_zip_is_not_mistaken_for_the_all_prefecture_zip_link(): void
    {
        // Regression test: the "新規指定一覧" (new designations only, a
        // monthly changelog) section has its own ZIP links that don't
        // contain "kikanzentai" at all, so it must not be picked up.
        $links = (new KinkiLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $filenames = array_map(fn ($link) => $link->filename, $links);

        $this->assertNotContains('2026.9_sinkikikan.zip', $filenames);
    }

    public function test_the_published_on_date_is_parsed_from_the_reiwa_era_em_tag(): void
    {
        $links = (new KinkiLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertSame('2026-09-01', $links[0]->publishedOn->toDateString());
    }
}
