<?php

namespace App\Services\Mhlw\Import;

use Generator;

/**
 * Streams one speciality-role dataset (hospital_speciality,
 * clinic_speciality, or dental_speciality -- all 3 share an identical
 * grouping/mapping shape, unlike the 5 facility datasets, so this needs no
 * dataset_key dispatch) into medical_facility_departments attribute arrays.
 * Pure array-in/array-out, same DB-free scope boundary as the rest of this
 * namespace.
 */
final class SpecialityDatasetRowSource
{
    public function __construct(
        private readonly SpecialityRowGrouper $grouper = new SpecialityRowGrouper,
        private readonly SpecialityRowMapper $mapper = new SpecialityRowMapper,
    ) {}

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(string $zipPath): Generator
    {
        foreach ($this->grouper->group((new MhlwCsvReader($zipPath))->rows()) as $group) {
            yield $this->mapper->mapGroup($group);
        }
    }
}
