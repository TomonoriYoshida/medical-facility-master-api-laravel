<?php

namespace App\Services\Rhb\Import;

/**
 * Column H carries an embedded 2-3 entry timeline across a record's rows:
 * the first non-empty value is always the original designation date; each
 * pair after that is a change-reason label (新規/組織変更/交代/その他 etc.,
 * not an exhaustively known set) followed by the date that change took
 * effect. This is history that predates our own import runs, so it is
 * mapped as a plain attribute (designated_on + designation_history) rather
 * than fed into the append-only medical_facility_events log, whose
 * semantics are "a change we ourselves observed during a sync run".
 */
final class DesignationHistoryParser
{
    public function __construct(
        private readonly JapaneseEraDateParser $dateParser = new JapaneseEraDateParser,
    ) {}

    /**
     * @param  list<array<int, string>>  $rows
     * @return array{designatedOn: ?string, history: list<array{reason: string, date: ?string}>}
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

        while ($values !== []) {
            $reason = array_shift($values);
            $date = array_shift($values);

            $history[] = [
                'reason' => $reason,
                'date' => $date !== null ? $this->dateParser->parse($date)?->toDateString() : null,
            ];
        }

        return ['designatedOn' => $designatedOn, 'history' => $history];
    }
}
