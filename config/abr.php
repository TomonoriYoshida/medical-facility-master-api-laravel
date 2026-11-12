<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Address Base Registry (アドレス・ベース・レジストリ)
    |--------------------------------------------------------------------------
    |
    | The Digital Agency's address master data, used to geocode facility
    | addresses (facilities:geocode). The files are served from this S3
    | bucket. The registry's catalog site (catalog.registries.digital.go.jp)
    | is gone; the Digital Agency's page for the registry
    | (www.digital.go.jp/policies/base_registry_address), which the API's
    | attribution links to, now points to dataset.address-br.digital.go.jp.
    |
    */

    'base_url' => env('ABR_BASE_URL', 'https://gov-csv-export-public.s3.ap-northeast-1.amazonaws.com'),

];
