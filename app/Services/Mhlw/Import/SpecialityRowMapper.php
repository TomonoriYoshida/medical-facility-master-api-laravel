<?php

namespace App\Services\Mhlw\Import;

/**
 * Turns one SpecialityRowGrouper-finalized group (up to 3 time-band rows
 * for one (ID, 診療科目コード)) into an attribute array for
 * medical_facility_departments. Pure array-in/array-out: does not look up
 * medical_facility_id, does not set last_seen_mhlw_dataset_download_id --
 * those belong to Phase 3's import-run logic. The returned array includes
 * source_id (not a real column on medical_facility_departments) so the
 * caller can resolve the owning facility before upserting; every other key
 * maps 1:1 to a medical_facility_departments column.
 */
final class SpecialityRowMapper
{
    private const int CONSULTATION_HOURS_START = 4;

    private const int RECEPTION_HOURS_START = 20;

    public function __construct(
        private readonly TimeSlotParser $timeSlotParser = new TimeSlotParser,
    ) {}

    /**
     * @param  array{sourceId: string, departmentCode: string, departmentName: ?string, bands: list<array<int, string>>}  $group
     * @return array<string, mixed>
     */
    public function mapGroup(array $group): array
    {
        $consultationHours = $this->timeSlotParser->emptyWeek(includeHoliday: true);
        $receptionHours = $this->timeSlotParser->emptyWeek(includeHoliday: true);

        foreach ($group['bands'] as $row) {
            $this->timeSlotParser->mergeBandInto(
                $consultationHours,
                $this->timeSlotParser->parseBand($row, self::CONSULTATION_HOURS_START, includeHoliday: true),
            );
            $this->timeSlotParser->mergeBandInto(
                $receptionHours,
                $this->timeSlotParser->parseBand($row, self::RECEPTION_HOURS_START, includeHoliday: true),
            );
        }

        return [
            'source_id' => $group['sourceId'],
            'department_code' => $group['departmentCode'],
            'department_name' => $group['departmentName'],
            'consultation_hours' => $consultationHours,
            'reception_hours' => $receptionHours,
        ];
    }
}
