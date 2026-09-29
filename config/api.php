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
    | Docs and Bulk Download Limits
    |--------------------------------------------------------------------------
    |
    | The docs pages sit outside the API's limiter, and a bulk download file
    | is megabytes rather than kilobytes, so both get their own per-IP limit.
    |
    */

    'docs_rate_limit_per_minute' => (int) env('DOCS_RATE_LIMIT_PER_MINUTE', 30),

    'export_downloads_per_hour' => (int) env('EXPORT_DOWNLOADS_PER_HOUR', 30),

];
