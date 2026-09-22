<?php

namespace App\Services\Mhlw\Import;

use Generator;

/**
 * Groups hospital/clinic/dental speciality (診療科目・診療時間) CSV rows by
 * (ID, 診療科目コード) -- one logical department is split across up to 3
 * physical rows in the source data (診療時間帯 = 1/2/3, a time-band index),
 * not one row per department. Rows for one group are guaranteed
 * row-contiguous in the real files, so this buffers-and-flushes-on-key-
 * change rather than materializing a groupBy() over the whole file (some of
 * these files are 600k+ rows).
 *
 * Only groups rows; does not parse hours into consultation_hours/
 * reception_hours -- that's SpecialityRowMapper's job, kept separate so
 * grouping (structural) and hour-parsing (semantic) don't tangle.
 */
final class SpecialityRowGrouper
{
    /**
     * @param  iterable<array<int, string>>  $rows
     * @return Generator<int, array{sourceId: string, departmentCode: string, departmentName: ?string, bands: list<array<int, string>>}>
     */
    public function group(iterable $rows): Generator
    {
        $bufferKey = null;
        $buffer = [];

        foreach ($rows as $row) {
            $key = [$row[0], $row[1]];

            if ($bufferKey !== null && $key !== $bufferKey) {
                yield $this->finalize($buffer);
                $buffer = [];
            }

            $buffer[] = $row;
            $bufferKey = $key;
        }

        if ($buffer !== []) {
            yield $this->finalize($buffer);
        }
    }

    /**
     * @param  list<array<int, string>>  $rows
     * @return array{sourceId: string, departmentCode: string, departmentName: ?string, bands: list<array<int, string>>}
     */
    private function finalize(array $rows): array
    {
        return [
            'sourceId' => $rows[0][0],
            'departmentCode' => $rows[0][1],
            'departmentName' => $this->firstNonEmptyDepartmentName($rows),
            'bands' => $rows,
        ];
    }

    /**
     * department_name should theoretically be identical across every row in
     * a group -- if messy source data disagrees, prefer the first non-empty
     * value rather than throwing (a parser crash on real government data is
     * worse than a mapper quietly preferring one value).
     *
     * @param  list<array<int, string>>  $rows
     */
    private function firstNonEmptyDepartmentName(array $rows): ?string
    {
        foreach ($rows as $row) {
            $name = trim($row[2] ?? '');

            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
