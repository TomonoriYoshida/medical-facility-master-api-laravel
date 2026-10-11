<?php

namespace Tests\Feature\Services\Geocoding;

use App\Services\Address\AddressMatchingNormalizer;
use App\Services\Geocoding\TownMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesAbrDatasets;
use Tests\TestCase;

class TownMatcherTest extends TestCase
{
    use FakesAbrDatasets;
    use RefreshDatabase;

    /**
     * @param  array{0: string, 1: string}|null  $expected  [machiaza_id, rest]
     */
    #[DataProvider('remainders')]
    public function test_it_finds_the_town_an_address_continues_with(string $remainder, ?array $expected): void
    {
        $matcher = new TownMatcher([
            $this->abrTown('202011', '0041000', '大字北堀'),
            $this->abrTown('202011', '0055134', '大字鶴賀', koaza: '田町'),
            $this->abrTown('202011', '0060119', '大字長野', koaza: '桜枝町'),
            $this->abrTown('202011', '0001001', '内幸町', chome: '1', residential: true),
            $this->abrTown('202011', '0001002', '内幸町', chome: '2', residential: true),
            $this->abrTown('202011', '0070000', '茅ヶ崎'),
            $this->abrTown('202011', '0025005', '福室', chome: '5', residential: true),
            $this->abrTown('202011', '0025104', '福室', koaza: '字下河原'),
            $this->abrTown('202011', '0025151', '福室', koaza: '字田中'),
            $this->abrTown('202011', '0077000', '広小路'),
            $this->abrTown('202011', '0077005', '広小路', chome: '5'),
            $this->abrTown('202011', '0022000', '字豊見城'),
            $this->abrTown('202011', '0090000', '東片町'),
            $this->abrTown('202011', '0037000', '桃山台'),
            $this->abrTown('202011', '0037002', '桃山台', chome: '2', residential: true, chomeSuffix: '丁'),
            $this->abrTown('202011', '0037102', '桃山台二丁田'),
            // 住居表示 covers only part of 岡三丁目, so it is listed with both flags.
            $this->abrTown('202011', '0021003', '岡', chome: '3', residential: true),
            $this->abrTown('202011', '0021003', '岡', chome: '3'),
            $this->abrTown('202011', '0021103', '岡', koaza: '字西'),
            $this->abrTown('202011', '0021103', '岡', koaza: '字西', residential: true),
        ], app(AddressMatchingNormalizer::class));

        $normalized = app(AddressMatchingNormalizer::class)->normalize($remainder);
        $match = $matcher->match($normalized);

        $this->assertSame($expected, $match === null ? null : [$match['id'], $match['rest']]);
    }

    public function test_a_town_listed_with_both_residential_flags_is_both_residential_and_parcel(): void
    {
        $matcher = new TownMatcher([
            $this->abrTown('202011', '0021003', '岡', chome: '3', residential: true),
            $this->abrTown('202011', '0021003', '岡', chome: '3'),
            $this->abrTown('202011', '0021004', '岡', chome: '4', residential: true),
            $this->abrTown('202011', '0021005', '岡', chome: '5'),
        ], app(AddressMatchingNormalizer::class));

        $flags = fn (string $remainder): array => array_intersect_key($matcher->match($remainder) ?? [], array_flip(['residential', 'parcel']));

        $this->assertSame(['residential' => true, 'parcel' => true], $flags('岡3丁目8'));
        $this->assertSame(['residential' => true, 'parcel' => false], $flags('岡4丁目8'));
        $this->assertSame(['residential' => false, 'parcel' => true], $flags('岡5丁目8'));
    }

    /**
     * @return array<string, array{string, array{0: string, 1: string}|null}>
     */
    public static function remainders(): array
    {
        return [
            '大字 omitted' => ['北堀649-1', ['0041000', '649-1']],
            '大字 written' => ['大字北堀649-1', ['0041000', '649-1']],
            '大字 and 小字' => ['鶴賀田町2447', ['0055134', '2447']],
            '小字 alone' => ['桜枝町1165', ['0060119', '1165']],
            '丁目 in kanji' => ['内幸町二丁目2番2号', ['0001002', '2番2号']],
            '丁目 in full-width digits' => ['内幸町１丁目６番１号', ['0001001', '6番1号']],
            '丁目 omitted before a hyphen' => ['内幸町1-6-1', ['0001001', '6-1']],
            'ケ for ヶ' => ['茅ケ崎3', ['0070000', '3']],
            'space before the numbers' => ['内幸町 1-6-1', ['0001001', '6-1']],
            '丁目 omitted where the town also has 小字' => ['福室5-10-5', ['0025005', '10-5']],
            '小字 of a town that also has 丁目' => ['福室字田中12', ['0025151', '12']],
            'bare town that also has 丁目' => ['広小路5-10', ['0077005', '10']],
            'bare town without a hyphen' => ['広小路208', ['0077000', '208']],
            '字 before a 大字' => ['豊見城444-2', ['0022000', '444-2']],
            'Kyoto street prefix' => ['高倉通姉小路下ル東片町621', ['0090000', '621']],
            '丁 in kanji where the registry writes 丁' => ['桃山台二丁3番4号', ['0037002', '3番4号']],
            '丁 in full-width digits where the registry writes 丁' => ['桃山台２丁３番４号', ['0037002', '3番4号']],
            '丁目 where the registry writes 丁' => ['桃山台二丁目3番4号', ['0037002', '3番4号']],
            '丁目 omitted before a hyphen where the registry writes 丁' => ['桃山台2-3-4', ['0037002', '3-4']],
            '丁 where the registry writes 丁目' => ['内幸町二丁2番2号', null],
            '丁 the town does not have' => ['桃山台九丁3番4号', null],
            'town name starting with a number and 丁' => ['桃山台二丁田5', ['0037102', '5']],
            'town listed with both 住居表示 flags' => ['岡三丁目8番8号', ['0021003', '8番8号']],
            '小字 alone of a town listed twice' => ['西12', ['0021103', '12']],
            'unknown town' => ['霞が関1-1', null],
        ];
    }
}
