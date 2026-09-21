<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MHLW Open Data Index Page
    |--------------------------------------------------------------------------
    |
    | The page listing download links for the 医療機能情報提供制度 (Medical
    | Function Information Provision System) open data CSVs.
    |
    */

    'index_url' => 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/kenkou_iryou/iryou/newpage_43373.html',

    'base_url' => 'https://www.mhlw.go.jp',

    /*
    |--------------------------------------------------------------------------
    | Datasets
    |--------------------------------------------------------------------------
    |
    | Each dataset is published as .../content/11121000/{slug}_{YYYYMMDD}.zip
    | (or occasionally "{slug}_{YYYYMMDD}.csv.zip" for the current snapshot).
    | Multiple historical dated snapshots stay live on the page at once, so
    | the slug alone identifies a dataset across time.
    |
    */

    'datasets' => [
        'hospital_facility' => [
            'label' => '病院（施設票）',
            'slug' => '01-1_hospital_facility_info',
        ],
        'hospital_speciality' => [
            'label' => '病院（診療科・診療時間票）',
            'slug' => '01-2_hospital_speciality_hours',
        ],
        'clinic_facility' => [
            'label' => '診療所（施設票）',
            'slug' => '02-1_clinic_facility_info',
        ],
        'clinic_speciality' => [
            'label' => '診療所（診療科・診療時間票）',
            'slug' => '02-2_clinic_speciality_hours',
        ],
        'dental_facility' => [
            'label' => '歯科診療所（施設票）',
            'slug' => '03-1_dental_facility_info',
        ],
        'dental_speciality' => [
            'label' => '歯科診療所（診療科・診療時間票）',
            'slug' => '03-2_dental_speciality_hours',
        ],
        'maternity_home' => [
            'label' => '助産所',
            'slug' => '04_maternity_home',
        ],
        'pharmacy' => [
            'label' => '薬局',
            'slug' => '05_pharmacy',
        ],
    ],

];
