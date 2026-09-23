<?php

namespace App\Services\Rhb\Import;

/**
 * Extracts column E's first row as a phone number, normalizing two
 * unambiguous formatting inconsistencies found in real data (verified
 * across all 8 bureaus, 224,517 facilities):
 * - Parenthesized area codes ("058(264)2525") are rewritten to hyphens
 *   ("058-264-2525") -- the digit groups themselves are untouched, only
 *   the separator changes, so there is no risk of fabricating digits.
 * - Doubled hyphens ("03-5284--8455", a plain typo) collapse to one.
 *
 * Beyond that, no format validation: real data was found to be >99.9%
 * well-formed, and the remaining edge cases (old-style numbers missing an
 * area code, a parenthesized number missing its leading digits, etc.)
 * are left as-is rather than guessing at the correct grouping -- they are
 * still legitimate phone numbers worth storing, not rejecting.
 */
final class PhoneNumberParser
{
    public function parse(string $raw): ?string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d+)\((\d+)\)(\d+)$/', $trimmed, $matches)) {
            $trimmed = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return preg_replace('/-{2,}/', '-', $trimmed);
    }
}
