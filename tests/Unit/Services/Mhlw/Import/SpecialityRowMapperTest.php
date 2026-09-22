<?php

namespace Tests\Unit\Services\Mhlw\Import;

use App\Services\Mhlw\Import\SpecialityRowGrouper;
use App\Services\Mhlw\Import\SpecialityRowMapper;
use PHPUnit\Framework\TestCase;

class SpecialityRowMapperTest extends TestCase
{
    public function test_it_merges_consultation_and_reception_hours_across_bands(): void
    {
        $band1 = $this->row('0001', '01001', '内科', '1');
        $band1[4] = '09:00';
        $band1[5] = '12:00'; // mon consultation
        $band1[20] = '08:45';
        $band1[21] = '11:00'; // mon reception

        $band2 = $this->row('0001', '01001', '内科', '2');
        $band2[4] = '14:00';
        $band2[5] = '17:00'; // mon consultation, second slot

        $groups = iterator_to_array((new SpecialityRowGrouper)->group([$band1, $band2]));
        $mapped = (new SpecialityRowMapper)->mapGroup($groups[0]);

        $this->assertSame('0001', $mapped['source_id']);
        $this->assertSame('01001', $mapped['department_code']);
        $this->assertSame('内科', $mapped['department_name']);
        $this->assertSame(
            [
                ['start' => '09:00', 'end' => '12:00'],
                ['start' => '14:00', 'end' => '17:00'],
            ],
            $mapped['consultation_hours']['mon'],
        );
        $this->assertSame(
            [['start' => '08:45', 'end' => '11:00']],
            $mapped['reception_hours']['mon'],
        );
        $this->assertSame([], $mapped['consultation_hours']['tue']);
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
