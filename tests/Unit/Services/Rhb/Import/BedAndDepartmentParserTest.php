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

    public function test_a_label_glued_to_its_number_by_half_width_spaces_keeps_no_spaces(): void
    {
        // Real data (関東信越・東北 and others): a label line "一般　　" and
        // then "　　一般 317", where the half-width space keeps the label
        // and its number in one token.
        $rows = [
            $this->row(i: "一般\u{3000}\u{3000}"),
            $this->row(i: "\u{3000}\u{3000}一般 317"),
            $this->row(i: "\u{3000}\u{3000}感染      4"),
        ];

        $result = (new BedAndDepartmentParser)->parse($rows);

        $this->assertSame(['一般' => 317, '感染' => 4], $result['bedCounts']);
    }

    public function test_beds_listed_on_several_lines_under_one_type_are_added_up(): void
    {
        // Real data: a type listed once per ward, either as label lines
        // (群馬県済生会前橋病院: 一般 317 and 6) or as label+number lines
        // (三沢市立三沢病院: 一般 50, 38, ...).
        $rows = [
            $this->row(i: "一般\u{3000}\u{3000}"),
            $this->row(i: "\u{3000}\u{3000}一般 317"),
            $this->row(i: "一般\u{3000}\u{3000}"),
            $this->row(i: "\u{3000}\u{3000}一般 6"),
            $this->row(i: "療養\u{3000}\u{3000} 51"),
            $this->row(i: "療養\u{3000}\u{3000} 17"),
        ];

        $result = (new BedAndDepartmentParser)->parse($rows);

        $this->assertSame(['一般' => 323, '療養' => 68], $result['bedCounts']);
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
