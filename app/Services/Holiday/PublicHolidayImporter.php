<?php

namespace App\Services\Holiday;

use App\Models\PublicHoliday;
use App\Services\Http\OpenDataHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replaces public_holidays with the Cabinet Office's current list. The list
 * is small (~1,070 rows), so it is read fully before the table is touched:
 * a list that fails to parse leaves the previous one in place.
 */
final class PublicHolidayImporter
{
    public function __construct(
        private readonly OpenDataHttp $http,
    ) {}

    /**
     * @return array{imported: int, until: Carbon}
     */
    public function import(): array
    {
        $url = config()->string('holidays.csv_url');
        $csv = mb_convert_encoding($this->http->get($url)->body(), 'UTF-8', 'SJIS-win');
        $now = now();
        $rows = [];

        foreach (preg_split('/\r\n|\n|\r/', $csv) ?: [] as $line) {
            // "2026/1/1,元日"; the header and blank lines do not match.
            if (preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2}),(.+)$#u', trim($line), $matches) !== 1) {
                continue;
            }

            $date = sprintf('%04d-%02d-%02d', $matches[1], $matches[2], $matches[3]);
            $rows[$date] = ['date' => $date, 'name' => trim($matches[4]), 'created_at' => $now, 'updated_at' => $now];
        }

        $minimum = config()->integer('holidays.minimum_holidays');

        if (count($rows) < $minimum) {
            throw new RuntimeException('Only '.count($rows)." holidays were read from {$url} (expected at least {$minimum}); the previous list was kept.");
        }

        ksort($rows);

        DB::transaction(function () use ($rows): void {
            PublicHoliday::query()->delete();
            PublicHoliday::query()->insert(array_values($rows));
        });

        return ['imported' => count($rows), 'until' => Carbon::parse((string) array_key_last($rows))];
    }
}
