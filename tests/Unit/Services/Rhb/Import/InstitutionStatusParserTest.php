<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Enums\MedicalFacilityStatus;
use App\Services\Rhb\Import\InstitutionStatusParser;
use PHPUnit\Framework\TestCase;

class InstitutionStatusParserTest extends TestCase
{
    public function test_a_plain_hospital_record_is_read_from_the_first_row(): void
    {
        $rows = [
            $this->row('病院'),
            $this->row('現存'),
        ];

        $result = (new InstitutionStatusParser)->parse($rows);

        $this->assertSame('病院', $result['typeLabel']);
        $this->assertSame(MedicalFacilityStatus::Active, $result['status']);
    }

    public function test_a_specific_function_hospital_sub_designation_does_not_hide_the_real_type_label(): void
    {
        // Regression test: real data shows a "特定機能" (specific-function
        // hospital) sub-designation occupying the row position a plain
        // 病院/診療所/薬局 record would use for its type label, with the
        // real "病院" value appearing on a later row instead.
        $rows = [
            $this->row('特定機能'),
            $this->row('病院'),
            $this->row('現存'),
        ];

        $result = (new InstitutionStatusParser)->parse($rows);

        $this->assertSame('病院', $result['typeLabel']);
    }

    public function test_a_suspended_facility_is_detected(): void
    {
        $rows = [
            $this->row('診療所'),
            $this->row('休止'),
        ];

        $result = (new InstitutionStatusParser)->parse($rows);

        $this->assertSame(MedicalFacilityStatus::Suspended, $result['status']);
    }

    /**
     * @return array<int, string>
     */
    private function row(string $j): array
    {
        $row = array_fill(0, 10, '');
        $row[9] = $j;

        return $row;
    }
}
