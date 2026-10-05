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
            'abbreviated corporation' => ['医療法人　成心会　なりた内科クリニック', '(医)成心会なりた内科クリニック'],
            'abbreviated corporation after 社団' => ['社団医療法人養生会　かしま病院', '社団(医)養生会かしま病院'],
            'punctuation' => ['みやざき内科・小児科クリニック', 'みやざき内科小児科クリニック'],
            'comma' => ['佐藤内科、小児科医院', '佐藤内科小児科医院'],
            'hyphen and long vowel mark' => ['ＩＭＳ　Ｍｅ－Ｌｉｆｅクリニック仙台', 'IMSMeーLifeクリニック仙台'],
            '付属 and 附属' => ['稲城市立病院付属　坂浜診療所', '稲城市立病院附属坂浜診療所'],
            '皮フ and 皮膚' => ['にっしん皮膚科・形成外科', 'にっしん皮フ科・形成外科'],
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
            '大字' => ['弘前市大字安原２丁目１－１３', '青森県弘前市安原2-1-13'],
            '字' => ['弘前市石川字石川１０３－５', '青森県弘前市石川石川103-5'],
            'kanji numbers in the town name' => ['虻田郡倶知安町ニセコひらふ５条３丁目７－１', '北海道虻田郡倶知安町ニセコひらふ五条3-7-1'],
            'kanji numbers over ten in the town name' => ['旭川市十二条通１６丁目', '北海道旭川市12条通16丁目'],
        ];
    }

    public function test_different_numbers_give_different_address_keys(): void
    {
        $keys = app(FacilityMatchingKeys::class);

        $this->assertNotSame($keys->address('千代田区丸の内1-9-1'), $keys->address('千代田区丸の内1-9-2'));
        // A floor is not part of the 番地.
        $this->assertNotSame($keys->address('千代田区丸の内1-9-1 2階'), $keys->address('千代田区丸の内1-9-12'));
        $this->assertNotSame($keys->address('旭川市二十三条通1'), $keys->address('旭川市二十条通1'));
        // Kanji numbers spelled digit by digit are left alone, not misread.
        $this->assertNotSame($keys->address('旭川市一五条通1'), $keys->address('旭川市5条通1'));
        // Numbers in the town name do not cut the key short.
        $this->assertNotSame($keys->address('札幌市中央区北1条西2丁目3'), $keys->address('札幌市中央区北1条西10丁目5'));
    }
}
