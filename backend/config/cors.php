<?php

/*
 * The embedded app calls the API from its own origin. Shopify admin extensions (product
 * page block, product list action) run on Shopify's extension domain and call it
 * cross-origin. Every API route authenticates with a bearer ID token, never cookies,
 * so allowing any origin exposes nothing without a valid token.
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    // Read by the app when it runs on its own domain: the file name of a download, rows an export left out.
    'exposed_headers' => ['Content-Disposition', 'X-Skipped-Rows'],
    'max_age' => 7200, // cache preflight requests
    'supports_credentials' => false,
];
