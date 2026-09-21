<?php

namespace Tests\Unit\Services\Mhlw;

use App\Services\Mhlw\MhlwDatasetLinkResolver;
use PHPUnit\Framework\TestCase;

class MhlwDatasetLinkResolverTest extends TestCase
{
    private const string BASE_URL = 'https://www.mhlw.go.jp';

    public function test_it_picks_the_link_with_the_largest_date_among_several_snapshots(): void
    {
        $html = <<<'HTML'
            <a href="/content/11121000/01-1_hospital_facility_info_20250601.zip">2025年6月</a>
            <a href="/content/11121000/01-1_hospital_facility_info_20251201.zip">2025年12月</a>
            <a href="/content/11121000/01-1_hospital_facility_info_20260601.csv.zip">2026年6月</a>
            HTML;

        $result = (new MhlwDatasetLinkResolver)->resolveLatest($html, '01-1_hospital_facility_info', self::BASE_URL);

        $this->assertNotNull($result);
        $this->assertSame('01-1_hospital_facility_info_20260601.csv.zip', $result->filename);
        $this->assertSame(
            'https://www.mhlw.go.jp/content/11121000/01-1_hospital_facility_info_20260601.csv.zip',
            $result->url,
        );
        $this->assertSame('2026-06-01', $result->publishedOn->toDateString());
    }

    public function test_it_ignores_links_belonging_to_other_dataset_slugs(): void
    {
        $html = <<<'HTML'
            <a href="/content/11121000/01-1_hospital_facility_info_20260601.csv.zip">病院</a>
            <a href="/content/11121000/01-2_hospital_speciality_hours_20261201.csv.zip">病院（診療科）</a>
            HTML;

        $result = (new MhlwDatasetLinkResolver)->resolveLatest($html, '01-1_hospital_facility_info', self::BASE_URL);

        $this->assertNotNull($result);
        $this->assertSame('01-1_hospital_facility_info_20260601.csv.zip', $result->filename);
    }

    public function test_it_returns_null_when_no_link_matches_the_slug(): void
    {
        $html = '<a href="/content/11121000/05_pharmacy_20260601.csv.zip">薬局</a>';

        $result = (new MhlwDatasetLinkResolver)->resolveLatest($html, '01-1_hospital_facility_info', self::BASE_URL);

        $this->assertNull($result);
    }
}
