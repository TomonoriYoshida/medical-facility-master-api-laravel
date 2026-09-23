<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Services\Rhb\Import\AddressParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AddressParserTest extends TestCase
{
    public function test_it_splits_postal_code_and_address(): void
    {
        $result = (new AddressParser)->parse('〒005－0813札幌市南区川沿１３条２丁目１番３８号');

        $this->assertSame('005-0813', $result['postalCode']);
        $this->assertSame('札幌市南区川沿１３条２丁目１番３８号', $result['address']);
    }

    public function test_it_throws_on_an_unexpected_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AddressParser)->parse('札幌市南区川沿１３条２丁目１番３８号');
    }
}
