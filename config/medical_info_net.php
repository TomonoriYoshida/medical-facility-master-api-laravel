<?php

use App\Enums\InstitutionType;

return [

    /*
    |--------------------------------------------------------------------------
    | 医療情報ネット (MHLW) open data
    |--------------------------------------------------------------------------
    |
    | The MHLW publishes the facilities of its 医療情報ネット twice a year
    | (June and December) as zipped CSVs, with their coordinates. Only the
    | coordinates are used (medical-info-net:import), to locate facilities
    | the Address Base Registry places no finer than a 町丁目. Published
    | under 公共データ利用規約（第1.0版）, like the bureaus' data.
    |
    */

    'index_url' => 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/kenkou_iryou/iryou/newpage_43373.html',

    'base_url' => 'https://www.mhlw.go.jp',

    // File name prefix on the index page => the facilities it lists.
    'datasets' => [
        '01-1_hospital_facility_info' => InstitutionType::Hospital,
        '02-1_clinic_facility_info' => InstitutionType::Clinic,
        '03-1_dental_facility_info' => InstitutionType::DentalClinic,
        '05_pharmacy' => InstitutionType::Pharmacy,
    ],

];
