<?php

use App\Enums\InstitutionType;
use App\Enums\MhlwDatasetRole;

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
    | institution_type/role identify, for the import pipeline, which
    | InstitutionType a dataset belongs to and whether it is the "facility"
    | (施設票) or "speciality" (診療科目票) half of that institution type's
    | data -- maternity_home/pharmacy have no speciality counterpart.
    |
    */

    'datasets' => [
        'hospital_facility' => [
            'label' => '病院（施設票）',
            'slug' => '01-1_hospital_facility_info',
            'institution_type' => InstitutionType::Hospital,
            'role' => MhlwDatasetRole::Facility,
        ],
        'hospital_speciality' => [
            'label' => '病院（診療科・診療時間票）',
            'slug' => '01-2_hospital_speciality_hours',
            'institution_type' => InstitutionType::Hospital,
            'role' => MhlwDatasetRole::Speciality,
        ],
        'clinic_facility' => [
            'label' => '診療所（施設票）',
            'slug' => '02-1_clinic_facility_info',
            'institution_type' => InstitutionType::Clinic,
            'role' => MhlwDatasetRole::Facility,
        ],
        'clinic_speciality' => [
            'label' => '診療所（診療科・診療時間票）',
            'slug' => '02-2_clinic_speciality_hours',
            'institution_type' => InstitutionType::Clinic,
            'role' => MhlwDatasetRole::Speciality,
        ],
        'dental_facility' => [
            'label' => '歯科診療所（施設票）',
            'slug' => '03-1_dental_facility_info',
            'institution_type' => InstitutionType::DentalClinic,
            'role' => MhlwDatasetRole::Facility,
        ],
        'dental_speciality' => [
            'label' => '歯科診療所（診療科・診療時間票）',
            'slug' => '03-2_dental_speciality_hours',
            'institution_type' => InstitutionType::DentalClinic,
            'role' => MhlwDatasetRole::Speciality,
        ],
        'maternity_home' => [
            'label' => '助産所',
            'slug' => '04_maternity_home',
            'institution_type' => InstitutionType::MaternityHome,
            'role' => MhlwDatasetRole::Facility,
        ],
        'pharmacy' => [
            'label' => '薬局',
            'slug' => '05_pharmacy',
            'institution_type' => InstitutionType::Pharmacy,
            'role' => MhlwDatasetRole::Facility,
        ],
    ],

];
