<?php

namespace App\Services\EPrescription;

use App\Enums\Prefecture;
use App\Services\Http\OpenDataHttp;
use App\Services\Rhb\Import\RhbXlsxReader;
use Generator;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Finds the MHLW's current list of the facilities that use 電子処方箋,
 * downloads it and reads each facility's start dates.
 */
final class EPrescriptionClient
{
    private const string DIRECTORY = 'e-prescriptions';

    /**
     * The columns read, found in the header row by how their title starts
     * (the titles carry furigana in some editions).
     */
    private const array COLUMNS = [
        'prefecture' => '都道府県名',
        'category' => '医療機関区分',
        'code' => '医療機関等コード',
        'started_on' => '電子処方箋の運用開始日',
        'refill_started_on' => '電子処方箋としてのリフィル処方箋の運用開始日',
    ];

    /**
     * The 点数表番号 that completes a 10-digit 医療機関コード, by the list's
     * 医療機関区分 (医科（病院）, 医科（診療所）, 歯科（診療所）, 薬局).
     */
    private const array SCORE_TABLES = ['医科' => '1', '歯科' => '3', '薬局' => '4'];

    public function __construct(
        private readonly OpenDataHttp $http,
    ) {}

    /**
     * The list's file and the date it is as of, from its link, which reads
     * 「電子処方せん対応の医療機関・薬局リスト（2026年10月11日時点）［8.2MB］」.
     *
     * @return array{as_of: Carbon, url: string}
     */
    public function latest(): array
    {
        $pageUrl = config()->string('e_prescriptions.page_url');
        $html = $this->http->get($pageUrl)->body();

        // A query string (a cache buster) may follow the file name.
        preg_match_all('#<a[^>]+href="([^"]+\.xlsx(?:\?[^"]*)?)"[^>]*>(.*?)</a>#siu', $html, $links, PREG_SET_ORDER);

        foreach ($links as [, $href, $label]) {
            $text = trim(strip_tags($label));

            if (str_contains($text, '医療機関・薬局リスト')
                && preg_match('/(\d{4})年(\d{1,2})月(\d{1,2})日時点/u', mb_convert_kana($text, 'n'), $date) === 1) {
                return [
                    'as_of' => Carbon::create((int) $date[1], (int) $date[2], (int) $date[3])
                        ?? throw new RuntimeException("Unreadable date in \"{$text}\"."),
                    'url' => str_starts_with($href, '/')
                        ? parse_url($pageUrl, PHP_URL_SCHEME).'://'.parse_url($pageUrl, PHP_URL_HOST).html_entity_decode($href)
                        : html_entity_decode($href),
                ];
            }
        }

        throw new RuntimeException("No list of the facilities using 電子処方箋 was found on {$pageUrl}.");
    }

    /**
     * Downloads a file into the local disk and returns its path.
     */
    public function download(string $url): string
    {
        return $this->http->download($url, self::DIRECTORY, timeout: 300);
    }

    /**
     * Each listed facility's start dates, by its 10-digit 医療機関コード
     * (都道府県番号 + 点数表番号 + the list's 7-digit 医療機関等コード).
     * Dates are Excel serial numbers, or text in some editions.
     *
     * Each facility's dates come as one string, "started_on|refill_started_on"
     * ("2026-08-01|" without a refill date): ~92,500 two-element arrays take
     * three times the memory next to the file's shared strings.
     *
     * @return Generator<string, string>
     */
    public function facilities(string $xlsxPath): Generator
    {
        $columns = null;

        foreach ((new RhbXlsxReader($xlsxPath))->rows() as $row) {
            if ($columns === null) {
                $columns = $this->headerColumns($row);

                continue;
            }

            $code = $row[$columns['code']] ?? '';

            if ($code === '') {
                continue;
            }

            $startedOn = $this->date($row[$columns['started_on']] ?? '')
                ?? throw new RuntimeException("No 電子処方箋の運用開始日 for {$code} in {$xlsxPath}.");

            yield $this->medicalInstitutionCode($row[$columns['prefecture']] ?? '', $row[$columns['category']] ?? '', $code) => $startedOn.'|'.$this->date($row[$columns['refill_started_on']] ?? '');
        }

        if ($columns === null) {
            throw new RuntimeException('No header row with every column ('.implode(', ', self::COLUMNS).") was found in {$xlsxPath}; the file's layout may have changed.");
        }
    }

    /**
     * The column of each title when $row is the header, or null for the
     * rows above it (the list's title and counts).
     *
     * @param  array<int, string>  $row
     * @return array<string, int>|null
     */
    private function headerColumns(array $row): ?array
    {
        $columns = [];

        foreach (self::COLUMNS as $key => $title) {
            foreach ($row as $index => $cell) {
                if (str_starts_with($cell, $title)) {
                    $columns[$key] = $index;

                    break;
                }
            }
        }

        return count($columns) === count(self::COLUMNS) ? $columns : null;
    }

    private function medicalInstitutionCode(string $prefectureName, string $category, string $code): string
    {
        $prefecture = array_find(Prefecture::cases(), fn (Prefecture $prefecture): bool => $prefecture->label() === $prefectureName)
            ?? throw new RuntimeException("Unknown prefecture \"{$prefectureName}\" for {$code}.");
        $scoreTable = array_find(self::SCORE_TABLES, fn (string $table, string $prefix): bool => str_starts_with($category, $prefix))
            ?? throw new RuntimeException("Unknown 医療機関区分 \"{$category}\" for {$code}.");

        // A code stored as a number loses its leading zeros.
        if (preg_match('/^\d{1,7}$/', $code) !== 1) {
            throw new RuntimeException("Unreadable 医療機関等コード \"{$code}\".");
        }

        return $prefecture->value.$scoreTable.str_pad($code, 7, '0', STR_PAD_LEFT);
    }

    /**
     * An Excel serial may carry a time as its fraction ("46235.5"), and a
     * text date a time after it ("2024/03/30 0:00:00"); only the date is kept.
     */
    private function date(string $value): ?string
    {
        $value = trim($value);

        return match (true) {
            $value === '' => null,
            preg_match('/^\d+(\.\d+)?$/', $value) === 1 => Carbon::create(1899, 12, 30)?->addDays((int) $value)->toDateString(),
            preg_match('#^(\d{4})[/-](\d{1,2})[/-](\d{1,2})(\s|$)#', $value, $parts) === 1 => sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]),
            default => throw new RuntimeException("Unreadable date \"{$value}\"."),
        };
    }
}
