<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Municipal population (総務省)
    |--------------------------------------------------------------------------
    |
    | 総務省「住民基本台帳に基づく人口、人口動態及び世帯数」, published yearly
    | (around summer) as of January 1. The Excel file of the whole population
    | (【総計】) by municipality (市区町村別) is used for counts per capita in
    | the stats API (population:import). Its file URL changes every year, so
    | it is found on the index page by the link text. Published under the
    | 政府標準利用規約, compatible with CC BY 4.0.
    |
    */

    'index_url' => 'https://www.soumu.go.jp/main_sosiki/jichi_gyousei/daityo/jinkou_jinkoudoutai-setaisuu.html',

    // A file with fewer municipalities than this was misread (Japan has
    // ~1,900 including the wards of designated cities); the import fails and
    // keeps the previous edition.
    'minimum_municipalities' => 1700,

];
