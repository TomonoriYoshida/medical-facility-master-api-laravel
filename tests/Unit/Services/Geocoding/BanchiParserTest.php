<?php

namespace Tests\Unit\Services\Geocoding;

use App\Services\Geocoding\BanchiParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BanchiParserTest extends TestCase
{
    /**
     * @param  array{0: string, 1: string|null}|null  $expected
     */
    #[DataProvider('numbers')]
    public function test_it_reads_the_leading_numbers(string $rest, ?array $expected): void
    {
        $this->assertSame($expected, (new BanchiParser)->parse($rest));
    }

    /**
     * @return array<string, array{string, array{0: string, 1: string|null}|null}>
     */
    public static function numbers(): array
    {
        return [
            '住居表示' => ['6番5号', ['6', '5']],
            'hyphens with a room number' => ['6-5-301', ['6', '5']],
            '地番 with 枝番' => ['545番地3', ['545', '3']],
            '地番 with の' => ['1の189', ['1', '189']],
            '番地の' => ['5番地の4', ['5', '4']],
            '地番 alone' => ['157番地', ['157', null]],
            'building after a space is ignored' => ['1-2 3階', ['1', '2']],
            'leading zeros' => ['06-05', ['6', '5']],
            'unlisted 小字 before the number' => ['字大門123-4', ['123', '4']],
            'building name is not a 小字' => ['ビル1階', null],
            'no number' => ['無番地', null],
        ];
    }
}
