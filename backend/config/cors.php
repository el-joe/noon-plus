<?php

/*
| Cross-Origin Resource Sharing for the API (G-W8). The storefront and apps
| on another origin read the guest cart and booking tokens from response
| headers, so they are exposed.
*/
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | QC-073: only the storefront (NAWY_STOREFRONT_URL) plus any extra origins
    | in NAWY_CORS_ORIGINS (comma-separated, e.g. the Flutter web builds).
    | Native apps send no Origin and are not affected.
    */
    'allowed_origins' => array_values(array_unique(array_filter(array_map(
        fn (string $origin): string => rtrim(trim($origin), '/'),
        [(string) env('NAWY_STOREFRONT_URL', 'http://nawy.test'), ...explode(',', (string) env('NAWY_CORS_ORIGINS', ''))],
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Cart-Token', 'X-Booking-Token', 'X-Application-Token', 'Retry-After', 'Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => false,

];
