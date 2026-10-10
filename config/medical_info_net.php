<?php

use App\Enums\InstitutionType;

return [

    /*
    |--------------------------------------------------------------------------
    | 医療情報ネット (MHLW) open data
    |--------------------------------------------------------------------------
    |
    | The MHLW publishes the facilities of its 医療情報ネット twice a year
    | (June and December) as zipped CSVs. Their coordinates locate facilities
    | the Address Base Registry places no finer than a 町丁目, and their
    | opening hours and days off are served per facility (both imported by
    | medical-info-net:import). Published under 公共データ利用規約（第1.0版）,
    | like the bureaus' data.
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

    // File name prefix => the facilities whose hours it lists, per
    // department (pharmacies have theirs in their own file above).
    'hours_datasets' => [
        '01-2_hospital_speciality_hours' => InstitutionType::Hospital,
        '02-2_clinic_speciality_hours' => InstitutionType::Clinic,
        '03-2_dental_speciality_hours' => InstitutionType::DentalClinic,
    ],

    // 医療情報ネット IDs of facilities that see no outpatients, though
    // their published hours say otherwise (the source has no field for it):
    // they get no opening periods, so open_at never finds them. Found by
    // hand; the reason goes next to each ID.
    'without_outpatients' => [
        '1311131300470', // 同善病院 (台東区): inpatients only, listed as open 24 hours every day
    ],

];
