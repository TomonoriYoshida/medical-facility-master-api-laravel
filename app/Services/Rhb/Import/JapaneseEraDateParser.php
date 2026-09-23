<?php

namespace App\Services\Rhb\Import;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Parses Japanese era-format dates as found in column H (e.g. "昭47. 3. 1"
 * = Showa 47 = 1972-03-01, "令5. 10. 23" = Reiwa 5 = 2023-10-23, "平元. 9. 1"
 * = the special case of a era's first year, written "元" rather than "1").
 */
final class JapaneseEraDateParser
{
    /**
     * @var array<string, int>
     */
    private const array ERA_START_YEARS = [
        '明' => 1868,
        '大' => 1912,
        '昭' => 1926,
        '平' => 1989,
        '令' => 2019,
    ];

    private const string PATTERN = '/^(明|大|昭|平|令)(元|\d+)\.\s*(\d+)\.\s*(\d+)$/u';

    public function parse(string $raw): ?CarbonImmutable
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        if (! preg_match(self::PATTERN, $trimmed, $matches)) {
            throw new InvalidArgumentException("Unexpected Japanese era date format: \"{$raw}\"");
        }

        $eraYear = $matches[2] === '元' ? 1 : (int) $matches[2];
        $year = self::ERA_START_YEARS[$matches[1]] + $eraYear - 1;

        return CarbonImmutable::create($year, (int) $matches[3], (int) $matches[4]);
    }
}
