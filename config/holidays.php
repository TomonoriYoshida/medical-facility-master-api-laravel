<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public holidays (内閣府)
    |--------------------------------------------------------------------------
    |
    | The Cabinet Office's list of 国民の祝日・休日 as one Shift_JIS CSV
    | ("2026/1/1,元日"), covering 1955 to the end of the next year; the next
    | year is usually added around February (holidays:import). Published
    | under the 政府標準利用規約, compatible with CC BY 4.0.
    |
    */

    'csv_url' => 'https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv',

    // A list with fewer holidays than this was misread (it has ~1,070 since
    // 1955); the import fails and keeps the previous list.
    'minimum_holidays' => 1000,

];
