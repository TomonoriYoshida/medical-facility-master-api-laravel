<?php

namespace Tests\Unit\Services\Mhlw\Import;

use App\Services\Mhlw\Import\SpecialityRowGrouper;
use PHPUnit\Framework\TestCase;

class SpecialityRowGrouperTest extends TestCase
{
    public function test_three_contiguous_rows_for_the_same_department_form_one_group(): void
    {
        $rows = [
            $this->row('0001', '01001', '内科', '1'),
            $this->row('0001', '01001', '内科', '2'),
            $this->row('0001', '01001', '内科', '3'),
        ];

        $groups = iterator_to_array((new SpecialityRowGrouper)->group($rows));

        $this->assertCount(1, $groups);
        $this->assertSame('0001', $groups[0]['sourceId']);
        $this->assertSame('01001', $groups[0]['departmentCode']);
        $this->assertCount(3, $groups[0]['bands']);
    }

    public function test_a_department_with_only_one_band_forms_its_own_group(): void
    {
        $rows = [
            $this->row('0001', '01001', '内科', '1'),
            $this->row('0002', '02001', '外科', '1'),
        ];

        $groups = iterator_to_array((new SpecialityRowGrouper)->group($rows));

        $this->assertCount(2, $groups);
        $this->assertCount(1, $groups[0]['bands']);
        $this->assertCount(1, $groups[1]['bands']);
    }

    public function test_key_change_flushes_the_previous_group_even_mid_band(): void
    {
        $rows = [
            $this->row('0001', '01001', '内科', '1'),
            $this->row('0001', '01001', '内科', '2'),
            $this->row('0001', '02001', '外科', '1'),
        ];

        $groups = iterator_to_array((new SpecialityRowGrouper)->group($rows));

        $this->assertCount(2, $groups);
        $this->assertSame('01001', $groups[0]['departmentCode']);
        $this->assertCount(2, $groups[0]['bands']);
        $this->assertSame('02001', $groups[1]['departmentCode']);
        $this->assertCount(1, $groups[1]['bands']);
    }

    public function test_a_blank_department_name_on_later_bands_does_not_override_the_first_non_empty_value(): void
    {
        $rows = [
            $this->row('0001', '01001', '内科', '1'),
            $this->row('0001', '01001', '', '2'),
        ];

        $groups = iterator_to_array((new SpecialityRowGrouper)->group($rows));

        $this->assertSame('内科', $groups[0]['departmentName']);
    }

    public function test_department_name_missing_on_every_band_yields_null(): void
    {
        $rows = [
            $this->row('0001', '01001', '', '1'),
        ];

        $groups = iterator_to_array((new SpecialityRowGrouper)->group($rows));

        $this->assertNull($groups[0]['departmentName']);
    }

    /**
     * @return array<int, string>
     */
    private function row(string $id, string $departmentCode, string $departmentName, string $band): array
    {
        $row = array_fill(0, 36, '');
        $row[0] = $id;
        $row[1] = $departmentCode;
        $row[2] = $departmentName;
        $row[3] = $band;

        return $row;
    }
}
