<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Only the frontend(s) listed in CORS_ALLOWED_ORIGINS (comma-separated) may
    | call the API from a browser. The framework default is "*", which let any
    | site send Authorization and X-API-KEY headers to this API.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Lets a browser frontend read how long to wait after a 429 on login.
    // Voucher downloads (SPEC-013): the file name, and which sent items the
    // template had no place for.
    'exposed_headers' => ['Retry-After', 'Content-Disposition', 'X-Voucher-Unplaced'],

    'max_age' => 0,

    'supports_credentials' => false,

];
