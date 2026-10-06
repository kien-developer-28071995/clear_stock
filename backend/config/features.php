<?php

/**
 * App-wide feature switches (every shop, every plan). A switched-off feature disappears:
 * no menu entry, page, button, upgrade prompt or pricing-page line, its API answers 404
 * `feature_disabled`, and its background work (jobs, webhooks, triggers) stops. Stored data
 * and merchant settings are kept, so switching it back on restores everything.
 *
 * Plans still decide who gets a feature that is switched on (config/billing.php).
 * Not switchable (the app is nothing without them): forecasts and their explanations, the
 * product list and product settings, suppliers, sync, onboarding, billing.
 *
 * Switches marked "surface" only hide the screens and close the API: what a merchant already
 * saved there keeps applying (entered costs, orders marked as placed, locations left out).
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
    'order_budget' => (bool) env('FEATURE_ORDER_BUDGET', true),       // monthly purchasing budget + priorities (Starter+)

    // Forecast quality and insight (every plan)
    'spike_filter' => (bool) env('FEATURE_SPIKE_FILTER', true),       // cap one-off sales spikes
    'lost_sales' => (bool) env('FEATURE_LOST_SALES', true),           // sales lost while out of stock
    'accuracy' => (bool) env('FEATURE_ACCURACY', true),               // past forecasts vs what really sold
    'sales_events' => (bool) env('FEATURE_SALES_EVENTS', true),       // promotions raising/lowering demand on set days

    // Reports
    'weekly_summary' => (bool) env('FEATURE_WEEKLY_SUMMARY', true),   // one summary email a week (every plan, opt-in)

    // Ordering
    'purchase_orders' => (bool) env('FEATURE_PURCHASE_ORDERS', true),   // PO export (CSV)
    // Shopify's own purchase orders, read-only (needs the optional scope read_inventory_purchase_orders in shopify.app.toml).
    'shopify_purchase_orders' => (bool) env('FEATURE_SHOPIFY_PURCHASE_ORDERS', true),
    'supplier_emails' => (bool) env('FEATURE_SUPPLIER_EMAILS', true),   // emailing orders to suppliers (by hand + automatic)

    // Multi-location (Growth). Transfers need locations.
    'locations' => (bool) env('FEATURE_LOCATIONS', true),
    'transfers' => (bool) env('FEATURE_TRANSFERS', true),

    // Automation (Growth)
    'realtime_alerts' => (bool) env('FEATURE_REALTIME_ALERTS', true),
    'flow_triggers' => (bool) env('FEATURE_FLOW_TRIGGERS', true),

    // Bundles and alerts (Starter+)
    'bundles' => (bool) env('FEATURE_BUNDLES', true),                   // bundle sales counted toward components
    'alerts' => (bool) env('FEATURE_ALERTS', true),                     // reorder summary emails (daily/weekly)
    'slack_alerts' => (bool) env('FEATURE_SLACK_ALERTS', true),         // the same summary posted to Slack (needs alerts)
    'low_cover_alerts' => (bool) env('FEATURE_LOW_COVER_ALERTS', true), // "also alert under N days of stock" (needs alerts)

    // Forecast options (every plan)
    'forecast_profiles' => (bool) env('FEATURE_FORECAST_PROFILES', true),   // window mix per shop/product; off = balanced
    'trend' => (bool) env('FEATURE_TREND', true),                           // selling faster/slower badge, filter, explanation line
    'order_exclusions' => (bool) env('FEATURE_ORDER_EXCLUSIONS', true),     // leave out orders by tag / POS / draft
    'location_exclusions' => (bool) env('FEATURE_LOCATION_EXCLUSIONS', true), // surface: locations not counted as stock

    // Ordering helpers (every plan)
    'manual_orders' => (bool) env('FEATURE_MANUAL_ORDERS', true),             // surface: "mark as ordered" + orders placed
    'alternate_suppliers' => (bool) env('FEATURE_ALTERNATE_SUPPLIERS', true), // backup suppliers per product
    'supplier_import' => (bool) env('FEATURE_SUPPLIER_IMPORT', true),         // suppliers from purchase order CSVs (Stocky)
    'vendor_suppliers' => (bool) env('FEATURE_VENDOR_SUPPLIERS', true),       // suppliers from Shopify vendors
    'costs' => (bool) env('FEATURE_COSTS', true),                             // surface: unit costs entered in the app

    // Product page and reorder list (every plan)
    'snooze' => (bool) env('FEATURE_SNOOZE', true),                       // "not now": hide a reorder suggestion until a later day
    'demand_projection' => (bool) env('FEATURE_DEMAND_PROJECTION', true), // expected sales over the next 30/60/90 days
    'change_log' => (bool) env('FEATURE_CHANGE_LOG', true),               // what was changed on a product, and when

    // Lists and reports (every plan)
    'saved_views' => (bool) env('FEATURE_SAVED_VIEWS', true),       // saved filters of the product list
    'product_export' => (bool) env('FEATURE_PRODUCT_EXPORT', true), // product list as CSV
    'stock_history' => (bool) env('FEATURE_STOCK_HISTORY', true),   // inventory units and value over time
    'clearance' => (bool) env('FEATURE_CLEARANCE', true),           // what to clear (slow + excess stock list)
    'size_runs' => (bool) env('FEATURE_SIZE_RUNS', true),           // products with sizes sold out
    'data_health' => (bool) env('FEATURE_DATA_HEALTH', true),       // product data check
];
