<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 国土数値情報「医療機関」(MLIT) data
    |--------------------------------------------------------------------------
    |
    | The MLIT's 国土数値情報 lists the hospitals, clinics and dental clinics
    | of each prefecture with their positions, read off maps (2020年度, not
    | updated since). Imported by national-land:import, they locate
    | facilities the Address Base Registry places no finer than a 町丁目 and
    | the 医療情報ネット does not locate. Published under CC BY 4.0.
    |
    */

    'page_url' => 'https://nlftp.mlit.go.jp/ksj/gml/datalist/KsjTmplt-P04-2020.html',

    // One zip per prefecture; {prefecture} is its two-digit code.
    'file_url' => 'https://nlftp.mlit.go.jp/ksj/gml/data/P04/P04-20/P04-20_{prefecture}_GML.zip',

];
