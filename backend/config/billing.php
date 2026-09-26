<?php

/**
 * Plans (flat price, no GMV share, no contract, cancel anytime). All charges go
 * through the Shopify Billing API. Annual = ~20% off 12 monthly payments.
 */
return [
    // Test charges (no real money). Must be false in production; dev stores can only use test charges.
    'test' => (bool) env('SHOPIFY_BILLING_TEST', true),

    'currency' => 'USD',

    // Free trial on paid plans, granted once per shop (switching plans uses the days left).
    'trial_days' => (int) env('SHOPIFY_BILLING_TRIAL_DAYS', 7),

    'plans' => [
        'free' => [
            'name' => 'Free',
            'prices' => null,
            'limits' => [
                'max_skus' => 50,          // forecasts for the 50 best-selling products
                'bundles' => false,
                // Transparent forecasts are the core promise: explanations are free on every plan.
                'explanations' => true,
                'alerts' => false,
                'locations' => false,
                'purchase_orders' => false,     // PO export (CSV) and emailing an order to a supplier
                'realtime_alerts' => false,
                'what_if' => false,
                'supplier_auto_email' => false, // automatic weekly orders to suppliers
                'flow_triggers' => false,       // Shopify Flow triggers
                'reference_products' => false,  // new products forecast from a similar product
                'transfers' => false,           // stock transfer suggestions between locations
                'supplier_emails' => false,     // emailing an order to a supplier by hand
                'abc' => true,                  // ABC classes by revenue
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'prices' => ['monthly' => 4.00, 'annual' => 38.00],
            'limits' => [
                'max_skus' => null,
                'bundles' => true,
                'explanations' => true,
                'alerts' => true,
                'locations' => false,
                'purchase_orders' => true,
                'realtime_alerts' => false,
                'what_if' => true,
                'supplier_auto_email' => false,
                'flow_triggers' => false,
                'reference_products' => true,
                'transfers' => false,
                'supplier_emails' => true,
                'abc' => true,
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            // Offered on the pricing page. Off: no new Growth subscriptions (e.g. while every
            // Growth-only feature is switched off in config/features.php); existing ones keep working.
            'offered' => (bool) env('BILLING_GROWTH_OFFERED', true),
            'prices' => ['monthly' => 6.00, 'annual' => 58.00],
            'limits' => [
                'max_skus' => null,
                'bundles' => true,
                'explanations' => true,
                'alerts' => true,
                'locations' => true,
                'purchase_orders' => true,
                'realtime_alerts' => true,
                'what_if' => true,
                'supplier_auto_email' => true,
                'flow_triggers' => true,
                'reference_products' => true,
                'transfers' => true,
                'supplier_emails' => true,
                'abc' => true,
            ],
        ],
    ],
];
