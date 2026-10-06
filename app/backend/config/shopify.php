<?php

return [
    // Merchant-facing name (technical slug: clear_stock).
    'app_name' => env('SHOPIFY_APP_NAME', 'Clear Stock'),

    // "Client ID" and "Client secret" from the Dev Dashboard.
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),

    'scopes' => env('SHOPIFY_SCOPES', 'read_products,read_inventory,read_locations,read_orders,read_all_orders,read_merchant_managed_fulfillment_orders,read_third_party_fulfillment_orders'),

    // Latest stable Admin API version (https://shopify.dev/docs/api/usage/versioning).
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),

    // Refresh the expiring offline token this many seconds before it expires.
    'token_refresh_margin' => 300,

    // Allowed clock skew when validating session tokens (seconds).
    'jwt_leeway' => 10,

    'http_timeout' => 30,

    'support_email' => env('SUPPORT_EMAIL', 'support@example.com'),

    // Days after install before the app may ask for an App Store review (once per shop).
    'review_prompt_after_days' => (int) env('REVIEW_PROMPT_AFTER_DAYS', 7),

    // Marketing website (website/ in the repo): home, /privacy, /support. The app's own
    // /privacy and /support redirect there. Dev: the website's `npm run dev`.
    'website_url' => rtrim((string) env('WEBSITE_URL', 'http://localhost:4321'), '/'),
    // The embedded app's own address (app/frontend/, a static site): what application_url in
    // shopify.app.toml names. This backend is the API it calls and serves no page of the app.
    'frontend_url' => rtrim((string) env('FRONTEND_URL', ''), '/') ?: null,
];
