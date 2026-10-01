<?php

namespace Tests\Feature\Services\Address;

use App\Services\Address\FacilityMatchingKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacilityMatchingKeysTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('sameNames')]
    public function test_names_of_the_same_facility_share_a_key(string $bureau, string $medicalInfoNet): void
    {
        $keys = app(FacilityMatchingKeys::class);

        $this->assertSame($keys->name($bureau), $keys->name($medicalInfoNet));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sameNames(): array
    {
        return [
            'corporation in front' => ['医療法人　愛全病院', '愛全病院'],
            'corporation with its own name' => ['医療法人社団明生会　イムス札幌病院', 'イムス札幌病院'],
            'full-width letters' => ['Ｔｏｋｙｏ　Ｓｔａｔｉｏｎ　Ｃｌｉｎｉｃ', 'Tokyo Station Clinic'],
        ];
    }

    #[DataProvider('sameAddresses')]
    public function test_addresses_of_the_same_place_share_a_key(string $bureau, string $medicalInfoNet): void
    {
        $keys = app(FacilityMatchingKeys::class);

        $this->assertSame($keys->address($bureau), $keys->address($medicalInfoNet));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sameAddresses(): array
    {
        return [
            '住居表示 and hyphens, prefecture and building' => ['札幌市中央区南１条西３丁目８番１号', '北海道札幌市中央区南1条西3丁目8-1エムズ札幌ビル5階'],
            'kanji 丁目' => ['千代田区丸の内一丁目９番１号', '東京都千代田区丸の内1-9-1'],
            '番地 and の' => ['八王子市北野町５４５番地３', '東京都八王子市北野町545の3'],
            'floor after a space' => ['千代田区丸の内一丁目９番１号　２階', '東京都千代田区丸の内1-9-1'],
            'space before the numbers' => ['仙台市宮城野区幸町　５－１０－１', '宮城県仙台市宮城野区幸町5-10-1'],
            'numbers in the town name' => ['札幌市中央区北１条西２丁目３番', '北海道札幌市中央区北1条西2丁目3'],
        ];
    }

    public function test_different_numbers_give_different_address_keys(): void
    {
        $keys = app(FacilityMatchingKeys::class);

        $this->assertNotSame($keys->address('千代田区丸の内1-9-1'), $keys->address('千代田区丸の内1-9-2'));
        // A floor is not part of the 番地.
        $this->assertNotSame($keys->address('千代田区丸の内1-9-1 2階'), $keys->address('千代田区丸の内1-9-12'));
        // Numbers in the town name do not cut the key short.
        $this->assertNotSame($keys->address('札幌市中央区北1条西2丁目3'), $keys->address('札幌市中央区北1条西10丁目5'));
    }
}
