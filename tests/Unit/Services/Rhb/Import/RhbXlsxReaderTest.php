<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Services\Rhb\Import\RhbXlsxReader;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsXlsxFixture;

class RhbXlsxReaderTest extends TestCase
{
    use BuildsXlsxFixture;

    protected function tearDown(): void
    {
        $this->cleanUpXlsxFixtures();

        parent::tearDown();
    }

    public function test_it_reads_rows_in_order(): void
    {
        $path = $this->createXlsx([
            ['header'],
            ['1', '01,1248,9', '愛全病院'],
            ['2', '01,1274,5', '平松記念病院'],
        ]);

        $rows = iterator_to_array((new RhbXlsxReader($path))->rows());

        $this->assertSame(['header'], $rows[0]);
        $this->assertSame(['1', '01,1248,9', '愛全病院'], $rows[1]);
        $this->assertSame(['2', '01,1274,5', '平松記念病院'], $rows[2]);
    }

    public function test_it_reports_sheet_names(): void
    {
        $path = $this->createXlsx([['a']], sheetName: '北海道');

        $this->assertSame(['北海道'], (new RhbXlsxReader($path))->sheetNames());
    }

    public function test_a_row_with_gaps_is_padded_with_empty_strings_up_to_its_own_max_column(): void
    {
        $path = $this->createXlsx([
            [0 => 'A', 3 => 'D'],
        ]);

        $rows = iterator_to_array((new RhbXlsxReader($path))->rows());

        $this->assertSame(['A', '', '', 'D'], $rows[0]);
    }

    public function test_rows_beyond_a_sparse_gap_are_still_read_in_order(): void
    {
        // Regression test: an earlier implementation called
        // XMLReader::next() after readOuterXml(), which -- since
        // readOuterXml() already advances the reader past the current
        // row's subtree -- silently skipped every other <row> element.
        // Verified directly against a real government file before this
        // test was written (2694 rows expected, only ~1347 were yielded).
        $path = $this->createXlsx([
            ['1'],
            ['2'],
            ['3'],
            ['4'],
            ['5'],
        ]);

        $rows = iterator_to_array((new RhbXlsxReader($path))->rows());

        $this->assertSame([['1'], ['2'], ['3'], ['4'], ['5']], $rows);
    }
}
