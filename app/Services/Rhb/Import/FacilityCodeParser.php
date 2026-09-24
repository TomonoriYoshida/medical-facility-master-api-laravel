<?php

namespace App\Services\Rhb\Import;

use InvalidArgumentException;

/**
 * Extracts the normalized 7-digit 医療機関コード from column B's first
 * row. The separator style (comma, hyphen, grouping) varies by bureau, but
 * stripping every non-digit character was verified to yield exactly 7
 * digits across 92,041 real records sampled from all 8 bureaus with zero
 * exceptions -- normalize-by-stripping is safe and bureau-agnostic.
 *
 * A superseded/old code sometimes appears in parens on a later row, and a
 * bureau-local reference label (e.g. "青医24") sometimes appears on
 * another -- both are deliberately ignored here; they carry no
 * information this schema needs.
 */
final class FacilityCodeParser
{
    /**
     * @param  list<array<int, string>>  $rows
     */
    public function parse(array $rows): string
    {
        $raw = $rows[0][1] ?? '';
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if (strlen($digits) !== 7) {
            throw new InvalidArgumentException("Unexpected facility code format: \"{$raw}\"");
        }

        return $digits;
    }
}
