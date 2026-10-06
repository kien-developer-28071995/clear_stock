<?php

/**
 * Clear Stock owner reports. This app reads the Clear Stock database (connection `app`,
 * ideally a read-only MySQL user) and keeps its own small ledger of installs, uninstalls
 * and plan changes, which survives the app deleting a shop's data on shop/redact.
 */
return [
    'app_name' => env('REPORT_APP_NAME', 'Clear Stock'),

    // Dates and "today" in the reports.
    'timezone' => env('REPORT_TIMEZONE', 'Asia/Ho_Chi_Minh'),

    // Plan prices (USD) for the MRR estimate: same as app/backend/config/billing.php. An estimate:
    // shops that subscribed at an older price keep it (price lock), trials pay nothing yet.
    'prices' => [
        'starter' => ['monthly' => (float) env('REPORT_PRICE_STARTER_MONTHLY', 4), 'annual' => (float) env('REPORT_PRICE_STARTER_ANNUAL', 38)],
        'growth' => ['monthly' => (float) env('REPORT_PRICE_GROWTH_MONTHLY', 6), 'annual' => (float) env('REPORT_PRICE_GROWTH_ANNUAL', 58)],
    ],

    // A shop counts as active when its forecasts were computed within this many days.
    'active_days' => 3,

    // Only these IPs may open the reports (comma separated; empty = any IP, login still required).
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('REPORT_ALLOWED_IPS', ''))))),

    // Feature usage is cached this long (seconds): the queries scan the app's tables.
    'cache_seconds' => (int) env('REPORT_CACHE_SECONDS', 300),
];
