<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limit
    |--------------------------------------------------------------------------
    |
    | Maximum requests per minute per client IP for the public, unauthenticated
    | API. Keyed by IP because there are no API users to key by. If a frontend
    | ever calls this API server-side (e.g. Next.js server components), every
    | visitor then shares that server's IP, so raise this accordingly.
    |
    */

    'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),

    /*
    |--------------------------------------------------------------------------
    | Page Depth
    |--------------------------------------------------------------------------
    |
    | Page numbers (offset pagination) reach only the first this-many rows of a
    | list: deep offsets cost the server the most and invite crawlers to walk
    | the whole table. Full copies come from the bulk download, or from the
    | facility list's cursor pagination (pagination=cursor), which has no limit.
    |
    */

    'max_paginated_rows' => (int) env('API_MAX_PAGINATED_ROWS', 10000),

    /*
    |--------------------------------------------------------------------------
    | Docs and Bulk Download Limits
    |--------------------------------------------------------------------------
    |
    | The docs pages sit outside the API's limiter, and a bulk download file
    | is megabytes rather than kilobytes, so both get their own per-IP limit.
    |
    */

    'docs_rate_limit_per_minute' => (int) env('DOCS_RATE_LIMIT_PER_MINUTE', 30),

    'export_downloads_per_hour' => (int) env('EXPORT_DOWNLOADS_PER_HOUR', 30),

    /*
    |--------------------------------------------------------------------------
    | Stats Computations
    |--------------------------------------------------------------------------
    |
    | The stats endpoints cache each answer for an hour, but any new filter
    | combination (a date range, a designation_reason) is computed afresh
    | over every matching facility. Only those computations count toward
    | this per-IP limit; cached answers are covered by the API's own limit.
    | The dashboard asks for up to five aggregates per filter change.
    |
    */

    'stats_computations_per_minute' => (int) env('STATS_COMPUTATIONS_PER_MINUTE', 30),

    /*
    |--------------------------------------------------------------------------
    | Access Log Check
    |--------------------------------------------------------------------------
    |
    | access-log:check reads the web server's access log (Caddy's JSON lines,
    | including rotated .gz files) every morning and alerts when the previous
    | day looks like an attack: many rate-limited (429) responses, many
    | not-found (404) responses as a vulnerability scanner produces, or one
    | IP sending far more requests than any visitor does. Syncing the whole
    | country page by page takes ~2,250 requests, under the per-IP threshold.
    |
    */

    'access_log' => [
        'path' => env('ACCESS_LOG_PATH', storage_path('logs/access.log')),
        'alert_thresholds' => [
            'rate_limited' => (int) env('ACCESS_ALERT_RATE_LIMITED', 50),
            'not_found' => (int) env('ACCESS_ALERT_NOT_FOUND', 200),
            'requests_per_ip' => (int) env('ACCESS_ALERT_REQUESTS_PER_IP', 3000),
        ],
    ],

];
