<?php

namespace App\Services\Rhb\Import;

/**
 * Extracts column E's first row as a phone number. No format validation
 * beyond trimming: real data was found to be >99.9% well-formed, and the
 * remaining edge cases (old-style numbers missing an area code, etc.) are
 * still legitimate phone numbers worth storing as-is rather than rejecting.
 */
final class PhoneNumberParser
{
    public function parse(string $raw): ?string
    {
        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
