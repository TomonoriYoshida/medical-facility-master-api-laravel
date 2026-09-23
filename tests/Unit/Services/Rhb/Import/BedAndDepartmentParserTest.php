<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Services\Rhb\Import\BedAndDepartmentParser;
use PHPUnit\Framework\TestCase;

class BedAndDepartmentParserTest extends TestCase
{
    public function test_a_bed_labels_number_lands_in_a_separate_token_and_is_still_paired_correctly(): void
    {
        // Regression test: splitting "療養　　 206" on the full-width
        // space delimiter yields ["療養", "206"] as two separate tokens,
        // not one glued "療養206" token -- verified against real data.
        $rows = [
            $this->row(i: "療養\u{3000}\u{3000} 206"),
        ];

        $result = (new BedAndDepartmentParser)->parse($rows);

        $this->assertSame(['療養' => 206], $result['bedCounts']);
        $this->assertSame([], $result['departmentTokens']);
    }

    public function test_multiple_bed_types_across_rows_are_all_collected(): void
    {
        $rows = [
            $this->row(i: "療養\u{3000}\u{3000} 206"),
            $this->row(i: "一般\u{3000}\u{3000} 231"),
        ];

        $result = (new BedAndDepartmentParser)->parse($rows);

        $this->assertSame(['療養' => 206, '一般' => 231], $result['bedCounts']);
    }

    public function test_a_department_token_line_has_no_digits_and_is_collected_as_is(): void
    {
        $rows = [
            $this->row(i: "内\u{3000}消化器内科\u{3000}循環器内科\u{3000}呼内\u{3000}脳内\u{3000}リハ\u{3000}歯"),
        ];

        $result = (new BedAndDepartmentParser)->parse($rows);

        $this->assertSame([], $result['bedCounts']);
        $this->assertSame(['内', '消化器内科', '循環器内科', '呼内', '脳内', 'リハ', '歯'], $result['departmentTokens']);
    }

    public function test_an_empty_column_i_contributes_nothing(): void
    {
        $rows = [$this->row(i: '')];

        $result = (new BedAndDepartmentParser)->parse($rows);

        $this->assertSame([], $result['bedCounts']);
        $this->assertSame([], $result['departmentTokens']);
    }

    /**
     * @return array<int, string>
     */
    private function row(string $i): array
    {
        $row = array_fill(0, 10, '');
        $row[8] = $i;

        return $row;
    }
}
