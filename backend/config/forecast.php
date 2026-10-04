<?php

/**
 * Forecast engine tuning. Every value here shows up in the forecast explanation,
 * so a merchant can always see which numbers were used.
 */
return [
    // Local hour (shop timezone) of the safety-net recompute when the nightly sync did not refresh forecasts.
    'nightly_hour' => 5,

    // Days of daily_sales history loaded per variant (the 28-day seasonality
    // comparison one year back needs 365 + 28 days).
    'history_days' => 400,

    // Averaging windows (days) and their weights. Weights are re-normalised over
    // the windows that have enough in-stock days.
    'windows' => [
        7 => ['weight' => 0.2, 'min_in_stock_days' => 4],
        30 => ['weight' => 0.5, 'min_in_stock_days' => 10],
        90 => ['weight' => 0.3, 'min_in_stock_days' => 20],
    ],

    // Forecast profiles: other window mixes a merchant can pick for the shop or for one product.
    // `balanced` is the windows above. Every profile keeps the 30- and 90-day windows (lost sales,
    // confidence and the explanation read them).
    'profiles' => [
        'balanced' => null,
        // New or fast-changing products: follows the last weeks closely.
        'recent' => [
            7 => ['weight' => 0.5, 'min_in_stock_days' => 4],
            30 => ['weight' => 0.4, 'min_in_stock_days' => 10],
            90 => ['weight' => 0.1, 'min_in_stock_days' => 20],
        ],
        // Steady or slow sellers: a full year smooths out quiet and busy weeks.
        'steady' => [
            30 => ['weight' => 0.2, 'min_in_stock_days' => 10],
            90 => ['weight' => 0.4, 'min_in_stock_days' => 20],
            365 => ['weight' => 0.4, 'min_in_stock_days' => 120],
        ],
    ],

    // Trend: sales per in-stock day over the last `recent_days` vs the `baseline_days` before them.
    // Reported when both periods have enough in-stock days and the baseline enough units; a change of
    // at least `threshold` (25%) counts as rising / falling. Information only: the rate is not changed.
    'trend' => [
        'recent_days' => 14,
        'baseline_days' => 42,
        'min_recent_in_stock_days' => 10,
        'min_baseline_in_stock_days' => 21,
        'min_baseline_units' => 10,
        'threshold' => 0.25,
        'max_percent' => 999,
    ],

    'seasonality' => [
        // Compare the next N days vs the previous N days, one year ago. A multiple of 7 so both
        // periods contain the same weekdays (weekly order patterns would otherwise look seasonal).
        'horizon_days' => 28,
        // Changes smaller than this (±10%) are treated as noise: no adjustment.
        'dead_band' => 0.1,
        // Both periods last year need this share of in-stock days...
        'min_in_stock_ratio' => 0.7,
        // ...and the earlier period at least this many units, otherwise the factor is noise.
        'min_units' => 10,
        'min_factor' => 0.5,
        'max_factor' => 2.5,
    ],

    // One-off spikes (a wholesale order, a viral day) in the last `days` days are capped to the usual
    // level: a day selling at least `min_units` and more than `factor` x the average of the other in-stock
    // days. Only for products with enough in-stock days and selling days to know what "usual" is.
    // More than `max_days` such days is a pattern (a weekly wholesale customer), not a spike: kept.
    // Merchants can switch it off in Settings (shops.filter_sales_spikes).
    'spikes' => [
        'days' => 90,
        'max_days' => 3,
        'factor' => 5,
        'min_units' => 10,
        'min_in_stock_days' => 20,
        'min_selling_days' => 5,
    ],

    // Stock counts as "slow-moving" (cash tied up) when it would last longer than this, or never sells.
    // Overstock: stock + on the way exceeds the order-up-to level by more than this share
    // (0.5 = 50% more than the forecast says to hold). Slow movers are reported as slow instead.
    'overstock_ratio' => 0.5,

    'slow_mover_days' => 180,

    // New products with a reference product: the estimate blends the reference's rate with the
    // product's own, in proportion to its in-stock days, and is fully its own after this many.
    'reference_full_after_days' => 30,

    // ABC classification by revenue (net units sold x current price) over the last `days`:
    // A = products making up the first 80% of revenue, B = up to 95%, C = the rest.
    'abc' => [
        'days' => 90,
        'a' => 0.8,
        'b' => 0.95,
    ],

    // Suggested order covers lead time + safety days + this many days of sales
    // (a supplier's own order cycle, suppliers.order_cycle_days, wins).
    'order_cycle_days' => 30,

    // Orders placed outside Shopify ("mark as ordered") count as on the way until received or cancelled,
    // and for this many days past their expected date (a late delivery); after that the merchant is asked.
    'manual_order_grace_days' => 3,

    // Forecast accuracy: each week's forecast rate (first full run from Monday, shop time) is compared
    // with what really sold per in-stock day over the next `horizon_days`. A product counts when it had
    // at least `min_in_stock_days` in stock in that period. Weekly snapshots are kept `keep_weeks`.
    'accuracy' => [
        'horizon_days' => 28,
        'min_in_stock_days' => 14,
        'weeks' => 8,       // weeks shown in the trend
        'keep_weeks' => 16,
    ],

    'confidence' => [
        'low_in_stock_days' => 14,  // fewer in-stock days in the last 90 => low
        'low_units' => 5,           // fewer units in the last 90 days => low
        'high_in_stock_days' => 60,
        'high_units' => 30,
        // Coefficient of variation of weekly sales (per in-stock day).
        'low_weekly_cv' => 1.0,
        'high_weekly_cv' => 0.5,
    ],
];
