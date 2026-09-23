<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Enums\RhbCategory;
use App\Services\Rhb\Download\KyushuLinkResolver;
use PHPUnit\Framework\TestCase;

class KyushuLinkResolverTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../../../Fixtures/rhb-kyushu-index.html');
    }

    public function test_it_resolves_three_category_links_per_office_zip(): void
    {
        // Regression test: unlike every other bureau, one office's zip
        // bundles all 3 categories for that one prefecture, so each
        // resolved zip must fan out into 3 links (one per category)
        // sharing the same url/filename.
        $links = (new KyushuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertCount(9, $links);

        $byFilename = [];
        foreach ($links as $link) {
            $byFilename[$link->filename][] = $link->category;
        }

        $this->assertCount(3, $byFilename);
        $this->assertEqualsCanonicalizing(RhbCategory::cases(), $byFilename['000500310.zip']);
        $this->assertEqualsCanonicalizing(RhbCategory::cases(), $byFilename['000500311.zip']);
        $this->assertEqualsCanonicalizing(RhbCategory::cases(), $byFilename['000500320.zip']);
    }

    public function test_the_okinawa_offices_stray_zip_link_around_its_name_is_not_mistaken_for_the_real_link(): void
    {
        // Regression test: real data shows the 沖縄 office's cell has its
        // office-name paragraph accidentally wrapped in an unrelated .zip
        // link (000500319.zip) in addition to the real "エクセルデータ
        // （ZIP）" link (000500320.zip) -- only the latter, matched by
        // link text, should be resolved.
        $links = (new KyushuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $filenames = array_map(fn ($link) => $link->filename, $links);

        $this->assertNotContains('000500319.zip', $filenames);
        $this->assertContains('000500320.zip', $filenames);
    }

    public function test_an_older_months_archived_table_is_not_mistaken_for_the_latest_one(): void
    {
        // Regression test: the page is a 12-month archive with one table
        // per month, all sharing the same structure -- only the first
        // (most recent) table's links must be resolved.
        $links = (new KyushuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $filenames = array_map(fn ($link) => $link->filename, $links);

        $this->assertNotContains('000496745.zip', $filenames);
    }

    public function test_the_published_on_date_is_the_latest_months_date_not_an_older_ones(): void
    {
        $links = (new KyushuLinkResolver)->resolve($this->fixture(), 'https://kouseikyoku.mhlw.go.jp');

        $this->assertSame('2026-09-01', $links[0]->publishedOn->toDateString());
    }
}
