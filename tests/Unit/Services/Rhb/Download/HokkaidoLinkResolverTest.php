<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Enums\RhbCategory;
use App\Services\Rhb\Download\HokkaidoLinkResolver;
use PHPUnit\Framework\TestCase;

class HokkaidoLinkResolverTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../../../Fixtures/rhb-hokkaido-index.html');
    }

    public function test_it_resolves_all_four_links_from_the_real_page_structure(): void
    {
        $html = $this->fixture();

        $links = (new HokkaidoLinkResolver)->resolve($html, 'https://kouseikyoku.mhlw.go.jp');

        $this->assertCount(4, $links);

        $this->assertSame(RhbCategory::Medical, $links[0]->category);
        $this->assertSame('https://kouseikyoku.mhlw.go.jp/hokkaido/000499337.xlsx', $links[0]->url);
        $this->assertSame('000499337.xlsx', $links[0]->filename);

        $this->assertSame(RhbCategory::Medical, $links[1]->category);
        $this->assertSame('000499339.xlsx', $links[1]->filename);

        $this->assertSame(RhbCategory::Dental, $links[2]->category);
        $this->assertSame('000499341.xlsx', $links[2]->filename);

        $this->assertSame(RhbCategory::Pharmacy, $links[3]->category);
        $this->assertSame('000499343.xlsx', $links[3]->filename);
    }

    public function test_the_published_on_date_is_parsed_from_the_reiwa_era_label(): void
    {
        $html = $this->fixture();

        $links = (new HokkaidoLinkResolver)->resolve($html, 'https://kouseikyoku.mhlw.go.jp');

        $this->assertSame('2026-09-01', $links[0]->publishedOn->toDateString());
    }

    public function test_both_medical_rows_resolve_to_the_same_category(): void
    {
        // Hokkaido publishes hospital and clinic data as two separate
        // files, both under RhbCategory::Medical -- the institution type
        // is distinguished per-record downstream, not by which file it
        // came from.
        $html = $this->fixture();

        $links = (new HokkaidoLinkResolver)->resolve($html, 'https://kouseikyoku.mhlw.go.jp');

        $medicalLinks = array_filter($links, fn ($link) => $link->category === RhbCategory::Medical);

        $this->assertCount(2, $medicalLinks);
    }
}
