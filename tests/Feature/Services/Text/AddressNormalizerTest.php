<?php

namespace Tests\Feature\Services\Text;

use App\Services\Text\AddressNormalizer;
use App\Services\Text\ItaijiNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_width_digits_and_hyphens_are_normalized_to_half_width(): void
    {
        $normalizer = new AddressNormalizer(new ItaijiNormalizer);

        $this->assertSame('世田谷区池尻3-15-5', $normalizer->normalize('世田谷区池尻３－１５－５'));
    }

    public function test_a_katakana_chōon_flanked_by_digits_is_rewritten_to_a_hyphen(): void
    {
        // Regression test: real data (8% of addresses) misuses the
        // katakana prolonged-sound mark "ー" as a banchi-number separator.
        $normalizer = new AddressNormalizer(new ItaijiNormalizer);

        $this->assertSame('浜松市中央区丸塚町157-1', $normalizer->normalize('浜松市中央区丸塚町１５７ー１'));
    }

    public function test_a_katakana_chōon_inside_a_building_name_is_left_unchanged(): void
    {
        // Regression test: the same mark is also legitimately used inside
        // katakana building names (e.g. "ガーデンハウス") -- flanked by
        // kana, not digits, so it must not be rewritten.
        $normalizer = new AddressNormalizer(new ItaijiNormalizer);

        $this->assertSame(
            '江戸川区篠崎町二丁目7番19号 ガーデンハウス弐番館1階',
            $normalizer->normalize('江戸川区篠崎町二丁目７番１９号　ガーデンハウス弐番館１階'),
        );
    }

    public function test_an_already_half_width_hyphenated_address_is_unchanged(): void
    {
        $normalizer = new AddressNormalizer(new ItaijiNormalizer);

        $this->assertSame('世田谷区池尻3-15-5', $normalizer->normalize('世田谷区池尻3-15-5'));
    }
}
