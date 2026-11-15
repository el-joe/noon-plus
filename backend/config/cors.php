<?php

/*
| Cross-Origin Resource Sharing for the API (G-W8). The storefront and apps
| on another origin read the guest cart and booking tokens from response
| headers, so they are exposed.
*/
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Cart-Token', 'X-Booking-Token', 'X-Application-Token', 'Retry-After'],

    'max_age' => 0,

    'supports_credentials' => false,

];
