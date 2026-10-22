<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 電子処方箋 (厚生労働省)
    |--------------------------------------------------------------------------
    |
    | The MHLW's list of the medical institutions and pharmacies that use
    | 電子処方箋, one Excel file updated weekly (usually on Fridays), with
    | each one's 医療機関等コード and the dates it started
    | (e-prescriptions:import). Its file URL may change, so it is found on
    | the page by the link text, which also gives the date the list is as of.
    | Published under the MHLW site's terms (PDL1.0).
    |
    */

    'page_url' => 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/denshishohousen_taioushisetsu.html',

    // A list with fewer facilities than this was misread (it had ~92,500 in
    // October 2026 and only grows); the import fails and keeps the dates.
    'minimum_facilities' => 50000,

];
