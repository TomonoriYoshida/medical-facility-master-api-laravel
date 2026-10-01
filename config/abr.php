<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Address Base Registry (アドレス・ベース・レジストリ)
    |--------------------------------------------------------------------------
    |
    | The Digital Agency's address master data, used to geocode facility
    | addresses (facilities:geocode). The files are served from this S3
    | bucket; the registry catalog (catalog.registries.digital.go.jp) links
    | to the same files.
    |
    */

    'base_url' => env('ABR_BASE_URL', 'https://gov-csv-export-public.s3.ap-northeast-1.amazonaws.com'),

];
