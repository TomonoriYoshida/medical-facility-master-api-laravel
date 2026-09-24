<?php

namespace App\Services\Rhb\Download;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Converts a bureau index page's "current as of" Japanese era date (元号+
 * 年+月+日+現在) into a Gregorian date. Every bureau's page seen so far
 * uses this same date format, but wrapped in different surrounding HTML
 * (Hokkaido: "【令和8年9月1日現在】"; Tohoku: an <h3> heading with no
 * brackets) -- so each BureauLinkResolver owns its own regex to locate and
 * capture the date within its bureau's markup (interpolating the shared
 * PATTERN fragment), then hands the 4 captured groups here for the actual
 * era-to-Gregorian-year conversion, which is identical across bureaus.
 */
final class IndexPageEraDateParser
{
    /**
     * Regex fragment with 4 capture groups: era name, era year ("元" or
     * digits), month, day. Callers interpolate this into their own
     * bureau-specific surrounding-markup regex.
     */
    public const string PATTERN = '(明治|大正|昭和|平成|令和)(元|\d+)年(\d+)月(\d+)日現在';

    /**
     * @var array<string, int>
     */
    private const array ERA_START_YEARS = [
        '明治' => 1868,
        '大正' => 1912,
        '昭和' => 1926,
        '平成' => 1989,
        '令和' => 2019,
    ];

    public function parse(string $era, string $eraYear, int $month, int $day): CarbonImmutable
    {
        $startYear = self::ERA_START_YEARS[$era]
            ?? throw new InvalidArgumentException("Unknown era name: \"{$era}\"");

        $yearOffset = $eraYear === '元' ? 1 : (int) $eraYear;

        return CarbonImmutable::create($startYear + $yearOffset - 1, $month, $day)
            ?? throw new InvalidArgumentException("Invalid date: {$era}{$eraYear}年{$month}月{$day}日");
    }
}
