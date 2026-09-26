<?php

/**
 * App-wide feature switches (every shop, every plan). A switched-off feature disappears:
 * no menu entry, page, button, upgrade prompt or pricing-page line, its API answers 404
 * `feature_disabled`, and its background work (jobs, webhooks, triggers) stops. Stored data
 * and merchant settings are kept, so switching it back on restores everything.
 *
 * Plans still decide who gets a feature that is switched on (config/billing.php).
 * The core promise is not switchable: forecasts, explanations, bundles, alerts, suppliers.
 *
 * Change with FEATURE_* env vars; production caches config, so restart the containers
 * (`php artisan config:cache` runs on start). Check the result: `php artisan features:status`.
 * Before switching `locations` or `transfers` read "Feature switches" in the README (scopes).
 */
return [
    // Growth-style planning
    'what_if' => (bool) env('FEATURE_WHAT_IF', true),
    'reference_products' => (bool) env('FEATURE_REFERENCE_PRODUCTS', true),
    'abc' => (bool) env('FEATURE_ABC', true),
    'purchase_plan' => (bool) env('FEATURE_PURCHASE_PLAN', true),     // 12-week order + spend plan (Starter+)

    // Forecast quality and insight (every plan)
    'spike_filter' => (bool) env('FEATURE_SPIKE_FILTER', true),       // cap one-off sales spikes
    'lost_sales' => (bool) env('FEATURE_LOST_SALES', true),           // sales lost while out of stock

    // Ordering
    'purchase_orders' => (bool) env('FEATURE_PURCHASE_ORDERS', true),   // PO export (CSV)
    'supplier_emails' => (bool) env('FEATURE_SUPPLIER_EMAILS', true),   // emailing orders to suppliers (by hand + automatic)

    // Multi-location (Growth). Transfers need locations.
    'locations' => (bool) env('FEATURE_LOCATIONS', true),
    'transfers' => (bool) env('FEATURE_TRANSFERS', true),

    // Automation (Growth)
    'realtime_alerts' => (bool) env('FEATURE_REALTIME_ALERTS', true),
    'flow_triggers' => (bool) env('FEATURE_FLOW_TRIGGERS', true),
];
