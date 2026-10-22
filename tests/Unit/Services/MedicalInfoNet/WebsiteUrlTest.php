<?php

namespace Tests\Unit\Services\MedicalInfoNet;

use App\Services\MedicalInfoNet\WebsiteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebsiteUrlTest extends TestCase
{
    /**
     * Values as they appear in the 医療情報ネット's files.
     *
     * @return array<string, array{string, string|null}>
     */
    public static function urls(): array
    {
        return [
            'https' => ['https://web.sapmed.ac.jp/hospital/', 'https://web.sapmed.ac.jp/hospital/'],
            'http with a query' => ['http://www.example.jp/?page=clinic', 'http://www.example.jp/?page=clinic'],
            'percent-encoded path' => ['https://www.marinegroup.jp/detail/%E6%84%9B/', 'https://www.marinegroup.jp/detail/%E6%84%9B/'],
            'surrounding spaces' => [' https://example.jp ', 'https://example.jp'],
            'no scheme' => ['www.kyogoku-clinic.com', 'http://www.kyogoku-clinic.com'],
            'no scheme with a path' => ['www3.ncv.ne.jp/~mcnet/kamome/', 'http://www3.ncv.ne.jp/~mcnet/kamome/'],
            'no colon' => ['http//www.teine-neuro.com', 'http://www.teine-neuro.com'],
            'no scheme with a port and query' => ['www.example.jp:8080?page=1', 'http://www.example.jp:8080?page=1'],
            'upper-case scheme' => ['HTTPS://WWW.EXAMPLE.JP/', 'https://WWW.EXAMPLE.JP/'],
            'japanese path' => ['https://example.jp/クリニック/', 'https://example.jp/%E3%82%AF%E3%83%AA%E3%83%8B%E3%83%83%E3%82%AF/'],
            'full-width' => ['ｈｔｔｐｓ：／／ｅｘａｍｐｌｅ．ｊｐ', 'https://example.jp'],
            'empty' => ['', null],
            'none written' => ['ﾅｼ', null],
            'misspelled scheme' => ['htt://san-c.co.jp', null],
            'another scheme' => ['javascript:alert(1)', null],
            'malformed host' => ['https://www.cosmo.-nc.com..', null],
            'too long' => ['https://example.jp/'.str_repeat('a', 500), null],
        ];
    }

    #[DataProvider('urls')]
    public function test_it_keeps_only_http_and_https_links_restoring_a_missing_scheme(string $value, ?string $expected): void
    {
        $this->assertSame($expected, (new WebsiteUrl)->normalize($value));
    }
}
