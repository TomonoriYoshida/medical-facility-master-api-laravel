<?php

namespace App\Services\Rhb\Import;

use Generator;

/**
 * Groups physical spreadsheet rows into logical records: a record starts
 * at a row whose column A holds a plain integer serial number, and
 * continues until the next such row (records are variable-length, 3-14
 * physical rows observed in real data). Skips any title-block rows before
 * the first record. Mirrors the old MHLW pipeline's SpecialityRowGrouper
 * buffer-and-flush pattern, but keyed on this content pattern rather than
 * a fixed column pair.
 */
final class RecordGrouper
{
    /**
     * @param  iterable<array<int, string>>  $rows
     * @return Generator<int, array{serial: int, rows: list<array<int, string>>}>
     */
    public function group(iterable $rows): Generator
    {
        $buffer = [];
        $serial = null;

        foreach ($rows as $row) {
            $a = trim($row[0] ?? '');

            if ($a !== '' && ctype_digit($a)) {
                if ($buffer !== []) {
                    yield ['serial' => $serial, 'rows' => $buffer];
                }

                $buffer = [];
                $serial = (int) $a;
            }

            if ($serial === null) {
                continue;
            }

            $buffer[] = $row;
        }

        if ($buffer !== []) {
            yield ['serial' => $serial, 'rows' => $buffer];
        }
    }
}
