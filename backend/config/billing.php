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
                'purchase_orders' => false,
                'realtime_alerts' => false,
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
                'purchase_orders' => false,
                'realtime_alerts' => false,
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            'prices' => ['monthly' => 5.00, 'annual' => 48.00],
            'limits' => [
                'max_skus' => null,
                'bundles' => true,
                'explanations' => true,
                'alerts' => true,
                'locations' => true,
                'purchase_orders' => true,
                'realtime_alerts' => true,
            ],
        ],
    ],
];
