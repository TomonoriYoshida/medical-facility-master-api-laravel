<?php

namespace Tests\Feature\Services\Address;

use App\Models\Municipality;
use App\Services\Address\MunicipalityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MunicipalityResolverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $municipalities  code => name
     */
    #[DataProvider('addresses')]
    public function test_it_resolves_the_municipality_an_address_starts_with(string $prefectureCode, array $municipalities, string $address, ?string $expected): void
    {
        foreach ($municipalities as $code => $name) {
            Municipality::factory()->create(['code' => (string) $code, 'prefecture_code' => $prefectureCode, 'name' => $name]);
        }

        $this->assertSame($expected, app(MunicipalityResolver::class)->resolve($prefectureCode, $address));
    }

    /**
     * @return array<string, array{string, array<string, string>, string, ?string}>
     */
    public static function addresses(): array
    {
        $sapporo = ['01100' => '札幌市', '01101' => '札幌市中央区'];

        return [
            'longest name wins over its parent' => ['01', $sapporo, '札幌市中央区北一条西２丁目', '01101'],
            'falls back to the parent when the ward is omitted' => ['01', $sapporo, '札幌市北一条', '01100'],
            'prefecture prefix is ignored' => ['01', $sapporo, '北海道札幌市中央区南１条', '01101'],
            'county may be omitted' => ['29', ['29363' => '磯城郡三宅町'], '三宅町大字三河', '29363'],
            'county may be present' => ['29', ['29363' => '磯城郡三宅町'], '磯城郡三宅町大字三河', '29363'],
            'ambiguous bare name is not matched without its county' => ['01', ['01001' => 'ア郡大町', '01002' => 'イ郡大町'], '大町１', null],
            'city may be omitted before its ward' => ['14', ['14110' => '横浜市戸塚区'], '戸塚区戸塚町16-1', '14110'],
            'a 郡 inside a city name is not a county' => ['29', ['29205' => '大和郡山市'], '山市１', null],
            'ambiguous ward is not matched without its city' => ['14', ['14114' => '横浜市緑区', '14151' => '相模原市緑区'], '緑区十日市場町', null],
            'ケ and ヶ are the same' => ['14', ['14203' => '茅ヶ崎市'], '茅ケ崎市元町', '14203'],
            'island prefix is skipped' => ['13', ['13401' => '八丈町'], '八丈島八丈町三根', '13401'],
            'unknown municipality' => ['01', $sapporo, '函館市本町', null],
        ];
    }

    public function test_it_only_matches_municipalities_of_the_given_prefecture(): void
    {
        Municipality::factory()->create(['code' => '13101', 'prefecture_code' => '13', 'name' => '千代田区']);

        $this->assertNull(app(MunicipalityResolver::class)->resolve('01', '千代田区神田'));
    }

    public function test_label_returns_the_municipality_name(): void
    {
        Municipality::factory()->create(['code' => '13101', 'prefecture_code' => '13', 'name' => '千代田区']);

        $this->assertSame('千代田区', app(MunicipalityResolver::class)->label('13101'));
        $this->assertNull(app(MunicipalityResolver::class)->label('99999'));
    }
}
