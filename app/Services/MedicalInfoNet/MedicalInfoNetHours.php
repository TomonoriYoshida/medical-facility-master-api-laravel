<?php

namespace App\Services\MedicalInfoNet;

/**
 * Reads opening hours and regular days off out of 医療情報ネット rows, per
 * the MHLW's オープンデータ定義書: hospitals, clinics and dental clinics list
 * their hours per department and time slot (時間帯 1-3) in a separate
 * 診療科・診療時間票, pharmacies list up to four slots on their own row.
 * Times are kept as published ("HH:MM"); about 3,000 of them across Japan
 * close earlier than they open, which is left for clients to show as it is.
 */
final class MedicalInfoNetHours
{
    /**
     * The source's day prefixes => the day names served.
     */
    public const array DAYS = ['月' => 'mon', '火' => 'tue', '水' => 'wed', '木' => 'thu', '金' => 'fri', '土' => 'sat', '日' => 'sun', '祝' => 'holiday'];

    /**
     * The days that can be regular days off => the source's prefixes.
     */
    private const array WEEKDAYS = ['mon' => '月', 'tue' => '火', 'wed' => '水', 'thu' => '木', 'fri' => '金', 'sat' => '土', 'sun' => '日'];

    private const int PHARMACY_SLOTS = 4;

    /**
     * One facility's rows of a 診療科・診療時間票. Departments with the same
     * hours are grouped into one schedule; slots without any time are left
     * out (each department lists all three, mostly empty).
     *
     * @param  list<array<string, string>>  $rows
     * @return list<array{departments: list<string>, slots: list<array{number: int, days: list<array{day: string, opens: ?string, closes: ?string, reception_opens: ?string, reception_closes: ?string}>}>}>
     */
    public function fromDepartmentRows(array $rows): array
    {
        $slotsByDepartment = [];

        foreach ($rows as $row) {
            $days = [];

            foreach (self::DAYS as $prefix => $day) {
                $hours = [
                    'opens' => $this->time($row["{$prefix}_診療開始時間"] ?? ''),
                    'closes' => $this->time($row["{$prefix}_診療終了時間"] ?? ''),
                    'reception_opens' => $this->time($row["{$prefix}_外来受付開始時間"] ?? ''),
                    'reception_closes' => $this->time($row["{$prefix}_外来受付終了時間"] ?? ''),
                ];

                if (array_filter($hours) !== []) {
                    $days[] = ['day' => $day, ...$hours];
                }
            }

            if ($days !== []) {
                // Prefixed so that a department named like a number stays a string key.
                $slotsByDepartment['_'.trim($row['診療科目名'] ?? '')][] = ['number' => (int) ($row['診療時間帯'] ?? 0), 'days' => $days];
            }
        }

        $schedules = [];

        foreach ($slotsByDepartment as $department => $slots) {
            $key = (string) json_encode($slots);
            $schedules[$key]['departments'][] = substr($department, 1);
            $schedules[$key]['slots'] = $slots;
        }

        return array_values($schedules);
    }

    /**
     * A pharmacy's opening hours, as one schedule with no departments (and
     * no reception hours, which pharmacies do not have).
     *
     * @param  array<string, string>  $row
     * @return list<array{departments: list<string>, slots: list<array{number: int, days: list<array{day: string, opens: ?string, closes: ?string, reception_opens: null, reception_closes: null}>}>}>
     */
    public function fromPharmacyRow(array $row): array
    {
        $slots = [];

        for ($number = 1; $number <= self::PHARMACY_SLOTS; $number++) {
            $days = [];

            foreach (self::DAYS as $prefix => $day) {
                $opens = $this->time($row["{$prefix}_開店時間帯{$number}_開始時間"] ?? '');
                $closes = $this->time($row["{$prefix}_開店時間帯{$number}_終了時間"] ?? '');

                if ($opens !== null || $closes !== null) {
                    $days[] = ['day' => $day, 'opens' => $opens, 'closes' => $closes, 'reception_opens' => null, 'reception_closes' => null];
                }
            }

            if ($days !== []) {
                $slots[] = ['number' => $number, 'days' => $days];
            }
        }

        return $slots === [] ? [] : [['departments' => [], 'slots' => $slots]];
    }

    /**
     * Regular days off from a facility (施設票) or pharmacy row. Despite their
     * names, the flag columns hold 1 for open and 0 for closed. A day of a
     * given week (第2水曜) is listed only when that day is not off every
     * week. Null when the row has none of it.
     *
     * @param  array<string, string>  $row
     * @return array{weekly: list<'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'>, monthly: list<array{week: int, day: 'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'}>, holidays: ?bool, other: ?string}|null
     */
    public function closures(array $row): ?array
    {
        $isPharmacy = array_key_exists('定期閉店毎週（月）', $row);
        $weeklyFlag = fn (string $prefix): string => $row[$isPharmacy ? "定期閉店毎週（{$prefix}）" : "毎週決まった曜日に休診（{$prefix}）"] ?? '';
        $monthlyFlag = fn (int $week, string $prefix): string => $row[$isPharmacy
            ? '定期閉店第'.mb_convert_kana((string) $week, 'N')."週（{$prefix}）"
            : "決まった週に休診（定期週）第{$week}週（{$prefix}）"] ?? '';
        $weeklyFlags = array_map($weeklyFlag, self::WEEKDAYS);
        $weekly = array_keys($weeklyFlags, '0', true);
        $hasFlags = implode('', $weeklyFlags) !== '';
        $monthly = [];

        for ($week = 1; $week <= 5; $week++) {
            foreach (self::WEEKDAYS as $day => $prefix) {
                $hasFlags = $hasFlags || $monthlyFlag($week, $prefix) !== '';

                if ($monthlyFlag($week, $prefix) === '0' && ! in_array($day, $weekly, true)) {
                    $monthly[] = ['week' => $week, 'day' => $day];
                }
            }
        }

        $holidayFlag = $row[$isPharmacy ? '祝日' : '祝日に休診'] ?? '';
        $other = trim((string) ($isPharmacy
            ? ($row['その他の閉店日（gw、お盆等）'] ?? $row['その他の閉店日（GW、お盆等）'] ?? '')
            : ($row['その他の休診日（gw、お盆等）'] ?? $row['その他の休診日（GW、お盆等）'] ?? '')));

        if (! $hasFlags && $holidayFlag === '' && $other === '') {
            return null;
        }

        return [
            'weekly' => $weekly,
            'monthly' => $monthly,
            'holidays' => match ($holidayFlag) {
                '0' => true,
                '1' => false,
                default => null,
            },
            // The source writes line breaks in it as "（改行）".
            'other' => $other === '' ? null : str_replace('（改行）', "\n", $other),
        ];
    }

    private function time(string $value): ?string
    {
        return preg_match('/^([01]\d|2[0-4]):[0-5]\d$/', $value) === 1 ? $value : null;
    }
}
