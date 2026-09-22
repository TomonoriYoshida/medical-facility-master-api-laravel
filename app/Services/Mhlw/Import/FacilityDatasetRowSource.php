<?php

namespace App\Services\Mhlw\Import;

use Closure;
use Generator;
use InvalidArgumentException;

/**
 * Dispatches a facility-role dataset_key (hospital_facility, clinic_facility,
 * dental_facility, maternity_home, pharmacy) to the right combination of
 * MhlwCsvReader + row mapper, yielding a uniform stream of
 * medical_facilities attribute arrays regardless of which of the 5 dataset
 * shapes it came from. Pure array-in/array-out, same DB-free scope boundary
 * as the rest of this namespace -- Phase 4's Jobs own resolving download
 * paths and calling the Sync layer.
 */
final class FacilityDatasetRowSource
{
    public function __construct(
        private readonly FacilityRowMapper $facilityRowMapper = new FacilityRowMapper,
        private readonly MaternityHomeRowMapper $maternityHomeRowMapper = new MaternityHomeRowMapper,
        private readonly PharmacyRowMapper $pharmacyRowMapper = new PharmacyRowMapper,
    ) {}

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(string $datasetKey, string $zipPath): Generator
    {
        $mapRow = $this->mapperFor($datasetKey);

        foreach ((new MhlwCsvReader($zipPath))->rows() as $row) {
            yield $mapRow($row);
        }
    }

    /**
     * @return Closure(array<int, string>): array<string, mixed>
     */
    private function mapperFor(string $datasetKey): Closure
    {
        return match ($datasetKey) {
            'hospital_facility' => fn (array $row): array => $this->facilityRowMapper->map($row, FacilityColumnLayout::hospital()),
            'clinic_facility' => fn (array $row): array => $this->facilityRowMapper->map($row, FacilityColumnLayout::clinic()),
            'dental_facility' => fn (array $row): array => $this->facilityRowMapper->map($row, FacilityColumnLayout::dental()),
            'maternity_home' => fn (array $row): array => $this->maternityHomeRowMapper->map($row),
            'pharmacy' => fn (array $row): array => $this->pharmacyRowMapper->map($row),
            default => throw new InvalidArgumentException("\"{$datasetKey}\" is not a facility dataset."),
        };
    }
}
