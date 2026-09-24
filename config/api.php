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

];
