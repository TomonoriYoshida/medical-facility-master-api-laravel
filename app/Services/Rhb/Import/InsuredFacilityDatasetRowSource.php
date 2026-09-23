<?php

namespace App\Services\Rhb\Import;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use Generator;

/**
 * Connects RhbXlsxReader -> RecordGrouper -> InsuredFacilityRecordMapper
 * into a single stream of medical_facilities attribute arrays for one
 * .xlsx sheet. DB-free, mirrors the old Import layer's
 * FacilityDatasetRowSource role.
 */
final class InsuredFacilityDatasetRowSource
{
    public function __construct(
        private readonly RecordGrouper $recordGrouper = new RecordGrouper,
        private readonly InsuredFacilityRecordMapper $recordMapper = new InsuredFacilityRecordMapper,
    ) {}

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(
        string $xlsxPath,
        RhbCategory $category,
        RhbBureau $bureau,
        string $prefectureCode,
        ?string $sheetName = null,
    ): Generator {
        $reader = new RhbXlsxReader($xlsxPath);

        foreach ($this->recordGrouper->group($reader->rows($sheetName)) as $record) {
            yield $this->recordMapper->map($record, $category, $bureau, $prefectureCode);
        }
    }
}
