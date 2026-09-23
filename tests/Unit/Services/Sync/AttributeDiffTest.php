<?php

namespace Tests\Unit\Services\Sync;

use App\Services\Sync\AttributeDiff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
        // Regression test: a decimal:6-cast column is always returned by
        // Laravel as a fixed 6-decimal string (verified: "35.500000",
        // never trimmed), while a row mapper typically produces a native
        // PHP float for the same column. An unnormalized === would flag
        // every row with such a column as "changed" on every reimport.
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
        $current = ['bed_counts' => ['psychiatric' => 50, 'general' => 100]];
        $mapped = ['bed_counts' => ['general' => 100, 'psychiatric' => 50]];

        $this->assertSame([], AttributeDiff::diff($current, $mapped));
    }

    public function test_json_array_content_changes_are_detected(): void
    {
        $current = ['bed_counts' => ['general' => 100]];
        $mapped = ['bed_counts' => ['general' => 90]];

        $this->assertSame(
            [
                'bed_counts' => [
                    'old' => ['general' => 100],
                    'new' => ['general' => 90],
                ],
            ],
            AttributeDiff::diff($current, $mapped),
        );
    }

    public function test_null_on_both_sides_is_not_a_change(): void
    {
        $current = ['bed_counts' => null];
        $mapped = ['bed_counts' => null];

        $this->assertSame([], AttributeDiff::diff($current, $mapped));
    }

    public function test_null_versus_a_value_is_a_change(): void
    {
        $current = ['bed_counts' => null];
        $mapped = ['bed_counts' => ['general' => 100]];

        $this->assertSame(
            ['bed_counts' => ['old' => null, 'new' => ['general' => 100]]],
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

    public function test_a_date_cast_carbon_instance_and_a_mapper_date_string_are_treated_as_equal(): void
    {
        // Regression test: a `date`-cast column is returned by Laravel as
        // a Carbon instance, never `===`-equal (nor is_scalar-comparable)
        // to a mapper's plain "Y-m-d" string -- every facility with such a
        // column would otherwise be flagged as "changed" on every reimport.
        $current = ['designated_on' => CarbonImmutable::parse('2023-10-23')];
        $mapped = ['designated_on' => '2023-10-23'];

        $this->assertSame([], AttributeDiff::diff($current, $mapped));
    }

    public function test_a_genuine_date_change_is_still_detected(): void
    {
        $current = ['designated_on' => CarbonImmutable::parse('2023-10-23')];
        $mapped = ['designated_on' => '2023-10-24'];

        // assertEquals, not assertSame: two separately-constructed Carbon
        // instances are never === to each other even for the same date.
        $this->assertEquals(
            ['designated_on' => ['old' => CarbonImmutable::parse('2023-10-23'), 'new' => '2023-10-24']],
            AttributeDiff::diff($current, $mapped),
        );
    }

    public function test_an_enum_collection_cast_and_a_mapper_plain_array_of_the_same_enums_are_treated_as_equal(): void
    {
        // Regression test: an AsEnumCollection-cast column is returned by
        // Laravel as an Illuminate\Support\Collection, which is neither
        // `===`-equal to nor even is_array()-true against a mapper's plain
        // array of the same enum instances.
        $current = ['department_categories' => new Collection(['internal_medicine', 'surgery'])];
        $mapped = ['department_categories' => ['internal_medicine', 'surgery']];

        $this->assertSame([], AttributeDiff::diff($current, $mapped));
    }

    public function test_a_genuine_collection_content_change_is_still_detected(): void
    {
        $current = ['department_categories' => new Collection(['internal_medicine'])];
        $mapped = ['department_categories' => ['surgery']];

        // assertEquals, not assertSame: the returned "old" value is the
        // original Collection instance from $current, and two separate
        // Collection instances are never === to each other even with
        // identical content.
        $this->assertEquals(
            ['department_categories' => ['old' => new Collection(['internal_medicine']), 'new' => ['surgery']]],
            AttributeDiff::diff($current, $mapped),
        );
    }
}
