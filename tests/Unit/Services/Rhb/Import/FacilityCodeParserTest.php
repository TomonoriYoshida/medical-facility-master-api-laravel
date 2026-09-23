<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Services\Rhb\Import\FacilityCodeParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FacilityCodeParserTest extends TestCase
{
    #[DataProvider('separatorStyleProvider')]
    public function test_it_normalizes_various_bureau_separator_styles_to_seven_digits(string $raw, string $expected): void
    {
        $rows = [[0 => '1', 1 => $raw]];

        $this->assertSame($expected, (new FacilityCodeParser)->parse($rows));
    }

    /**
     * @return list<list<string>>
     */
    public static function separatorStyleProvider(): array
    {
        return [
            'Hokkaido comma 2-4-1' => ['01,1248,9', '0112489'],
            'Tohoku hyphen 2-4-1' => ['01-1024-3', '0110243'],
            'Kanto-Shinetsu comma 3-3-1' => ['030,176,2', '0301762'],
            'Kinki hyphen 2-5' => ['01-00153', '0100153'],
        ];
    }

    public function test_it_throws_when_the_stripped_digit_count_is_not_seven(): void
    {
        $rows = [[0 => '1', 1 => '01,123']];

        $this->expectException(InvalidArgumentException::class);

        (new FacilityCodeParser)->parse($rows);
    }
}
