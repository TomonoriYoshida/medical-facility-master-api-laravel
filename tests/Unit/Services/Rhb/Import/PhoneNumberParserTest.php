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
}
