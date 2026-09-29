<?php

namespace App\Services\Rhb\Import;

/**
 * Column H carries an embedded timeline across a record's rows: the first
 * non-empty value is always the original designation date; after that come
 * entries of a reason label (新規/組織変更/交代/その他 etc., not an
 * exhaustively known set) followed by a date. The label is optional: about
 * 10% of real records, across every bureau, carry the date alone, so each
 * value is classified by whether it is a date rather than by position.
 *
 * Observed on real data, the date equals designated_on for facilities
 * designated within the last 6 years and is later for older ones -- in line
 * with the 6-yearly renewal of a designation, so it reads as the start of
 * the current designation period, and the label as the registration reason.
 *
 * This is history that predates our own import runs, so it is mapped as a
 * plain attribute (designated_on + designation_history) rather than fed
 * into the append-only medical_facility_events log, whose semantics are "a
 * change we ourselves observed during a sync run".
 */
final class DesignationHistoryParser
{
    public function __construct(
        private readonly JapaneseEraDateParser $dateParser = new JapaneseEraDateParser,
    ) {}

    /**
     * @param  list<array<int, string>>  $rows
     * @return array{designatedOn: ?string, history: list<array{reason: ?string, date: ?string}>}
     */
    public function parse(array $rows): array
    {
        $values = [];

        foreach ($rows as $row) {
            $h = trim($row[7] ?? '');

            if ($h !== '') {
                $values[] = $h;
            }
        }

        if ($values === []) {
            return ['designatedOn' => null, 'history' => []];
        }

        $designatedOn = $this->dateParser->parse(array_shift($values))?->toDateString();

        $history = [];
        $pendingReason = null;

        foreach ($values as $value) {
            if (! $this->dateParser->isDate($value)) {
                // Two labels in a row: the first had no date of its own.
                if ($pendingReason !== null) {
                    $history[] = ['reason' => $pendingReason, 'date' => null];
                }

                $pendingReason = $value;

                continue;
            }

            $history[] = ['reason' => $pendingReason, 'date' => $this->dateParser->parse($value)?->toDateString()];
            $pendingReason = null;
        }

        if ($pendingReason !== null) {
            $history[] = ['reason' => $pendingReason, 'date' => null];
        }

        return ['designatedOn' => $designatedOn, 'history' => $history];
    }
}
