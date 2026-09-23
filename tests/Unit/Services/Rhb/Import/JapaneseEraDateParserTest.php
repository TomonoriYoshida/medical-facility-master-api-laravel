<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Services\Rhb\Import\JapaneseEraDateParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JapaneseEraDateParserTest extends TestCase
{
    #[DataProvider('eraDateProvider')]
    public function test_it_converts_era_dates_to_gregorian(string $raw, string $expected): void
    {
        $result = (new JapaneseEraDateParser)->parse($raw);

        $this->assertSame($expected, $result->toDateString());
    }

    /**
     * @return list<list<string>>
     */
    public static function eraDateProvider(): array
    {
        return [
            'Showa 47' => ['昭47. 3. 1', '1972-03-01'],
            'Reiwa 5' => ['令5. 10. 23', '2023-10-23'],
            'Heisei 25' => ['平25. 6. 1', '2013-06-01'],
            'first year written as 元' => ['平元. 9. 1', '1989-09-01'],
            'Meiji' => ['明30. 1. 1', '1897-01-01'],
            'Taisho' => ['大5. 4. 1', '1916-04-01'],
        ];
    }

    public function test_an_empty_value_returns_null(): void
    {
        $this->assertNull((new JapaneseEraDateParser)->parse(''));
    }

    public function test_it_throws_on_an_unexpected_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JapaneseEraDateParser)->parse('2023-10-23');
    }
}
