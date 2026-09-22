<?php

namespace Tests\Unit\Services\Mhlw\Sync;

use App\Services\Mhlw\Sync\AttributeDiff;
use PHPUnit\Framework\TestCase;

class AttributeDiffTest extends TestCase
{
    public function test_no_changes_yields_an_empty_diff(): void
    {
        $current = ['name' => '髙橋病院', 'address' => '東京都千代田区'];
        $mapped = ['name' => '髙橋病院', 'address' => '東京都千代田区'];

        $this->assertSame([], AttributeDiff::diff($current, $mapped));
    }

    public function test_a_changed_field_is_reported_with_old_and_new_values(): void
    {
        $current = ['name' => '旧名称', 'address' => '東京都千代田区'];
        $mapped = ['name' => '新名称', 'address' => '東京都千代田区'];

        $this->assertSame(
            ['name' => ['old' => '旧名称', 'new' => '新名称']],
            AttributeDiff::diff($current, $mapped),
        );
    }

    public function test_decimal_cast_string_and_mapper_float_are_treated_as_equal(): void
    {
        // Regression test: MedicalFacility::latitude/longitude are cast
        // decimal:6, so Laravel always returns them as fixed 6-decimal
        // strings (verified: "35.500000", never trimmed), while every row
        // mapper produces a native PHP float for the same column. An
        // unnormalized === would flag every facility with coordinates as
        // "changed" on every single reimport.
        $current = ['latitude' => '35.658581', 'longitude' => '139.700592'];
        $mapped = ['latitude' => 35.658581, 'longitude' => 139.700592];

        $this->assertSame([], AttributeDiff::diff($current, $mapped));
    }

    public function test_a_genuine_latitude_change_is_still_detected(): void
    {
        $current = ['latitude' => '35.658581'];
        $mapped = ['latitude' => 35.658582];

        $this->assertSame(
            ['latitude' => ['old' => '35.658581', 'new' => 35.658582]],
            AttributeDiff::diff($current, $mapped),
        );
    }

    public function test_a_float_with_trailing_zeros_matches_the_fully_padded_decimal_string(): void
    {
        // decimal:6 never trims trailing zeros (35.5 round-trips as
        // "35.500000"), so the float side must be padded the same way
        // rather than trimmed, or this would falsely report a change.
        $current = ['latitude' => '35.500000'];
        $mapped = ['latitude' => 35.5];

        $this->assertSame([], AttributeDiff::diff($current, $mapped));
    }

    public function test_numeric_looking_string_codes_are_compared_literally_not_as_decimals(): void
    {
        // prefecture_code "01" must not be reformatted as a decimal (which
        // would silently equate it with "1") -- codes are compared as
        // plain strings.
        $current = ['prefecture_code' => '01'];
        $mapped = ['prefecture_code' => '01'];

        $this->assertSame([], AttributeDiff::diff($current, $mapped));

        $mapped2 = ['prefecture_code' => '02'];
        $this->assertSame(
            ['prefecture_code' => ['old' => '01', 'new' => '02']],
            AttributeDiff::diff($current, $mapped2),
        );
    }

    public function test_json_arrays_with_different_key_order_but_equal_content_are_not_flagged(): void
    {
        $current = ['closure_schedule' => ['weekly' => ['sun' => 0, 'mon' => 1]]];
        $mapped = ['closure_schedule' => ['weekly' => ['mon' => 1, 'sun' => 0]]];

        $this->assertSame([], AttributeDiff::diff($current, $mapped));
    }

    public function test_json_array_content_changes_are_detected(): void
    {
        $current = ['closure_schedule' => ['weekly' => ['mon' => 1]]];
        $mapped = ['closure_schedule' => ['weekly' => ['mon' => 0]]];

        $this->assertSame(
            [
                'closure_schedule' => [
                    'old' => ['weekly' => ['mon' => 1]],
                    'new' => ['weekly' => ['mon' => 0]],
                ],
            ],
            AttributeDiff::diff($current, $mapped),
        );
    }

    public function test_null_on_both_sides_is_not_a_change(): void
    {
        $current = ['business_hours' => null];
        $mapped = ['business_hours' => null];

        $this->assertSame([], AttributeDiff::diff($current, $mapped));
    }

    public function test_null_versus_a_value_is_a_change(): void
    {
        $current = ['business_hours' => null];
        $mapped = ['business_hours' => ['mon' => []]];

        $this->assertSame(
            ['business_hours' => ['old' => null, 'new' => ['mon' => []]]],
            AttributeDiff::diff($current, $mapped),
        );
    }

    public function test_a_key_missing_from_current_is_treated_as_null(): void
    {
        $current = [];
        $mapped = ['name' => '新規施設'];

        $this->assertSame(
            ['name' => ['old' => null, 'new' => '新規施設']],
            AttributeDiff::diff($current, $mapped),
        );
    }
}
