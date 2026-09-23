<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Services\Rhb\Import\PhoneNumberParser;
use PHPUnit\Framework\TestCase;

class PhoneNumberParserTest extends TestCase
{
    public function test_it_returns_the_trimmed_value(): void
    {
        $this->assertSame('011-571-5670', (new PhoneNumberParser)->parse('011-571-5670'));
    }

    public function test_an_empty_value_becomes_null(): void
    {
        $this->assertNull((new PhoneNumberParser)->parse(''));
        $this->assertNull((new PhoneNumberParser)->parse('   '));
    }

    public function test_a_parenthesized_area_code_is_rewritten_to_hyphens(): void
    {
        // Regression test: real data (2.1% of 224,517 facilities) uses
        // "058(264)2525"-style formatting instead of hyphens -- the digit
        // groups themselves are untouched, only the separator changes.
        $this->assertSame('058-264-2525', (new PhoneNumberParser)->parse('058(264)2525'));
    }

    public function test_a_doubled_hyphen_typo_is_collapsed_to_one(): void
    {
        $this->assertSame('03-5284-8455', (new PhoneNumberParser)->parse('03-5284--8455'));
    }

    public function test_a_parenthesized_value_missing_its_leading_digits_is_left_unchanged(): void
    {
        // Regression test: real data has a small number of values like
        // "(53)3998" with no digits before the opening paren -- rewriting
        // this would require guessing the missing leading digits, so it
        // is left as-is rather than fabricated.
        $this->assertSame('(53)3998', (new PhoneNumberParser)->parse('(53)3998'));
    }

    public function test_a_short_number_with_no_area_code_is_left_unchanged(): void
    {
        $this->assertSame('656-8522', (new PhoneNumberParser)->parse('656-8522'));
    }
}
