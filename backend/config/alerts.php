<?php

return [
    // Local hour (shop timezone) from which the digest can go out; forecasts are ready by then.
    'send_hour' => 8,

    // A product already included in a digest is only reported as "new" again after this many
    // days, or sooner if it went from "reorder soon" to "out of stock". No daily repeats.
    'realert_days' => 7,

    // Products listed in one email (the rest are summarised as "and N more").
    'max_items' => 20,
    'slack_max_items' => 15,

    // Automatic purchase order emails to a supplier: at most one per this many days.
    'supplier_auto_interval_days' => 7,

    // Don't email from forecasts older than this (e.g. the nightly sync kept failing).
    'max_forecast_age_hours' => 36,

    // Real-time alerts (Growth): live stock from inventory_levels/update webhooks, checked
    // against the latest forecast of the whole variant (all locations). Anti-spam layers:
    // only worsening transitions, hysteresis before re-arming, the shared realert_days
    // cooldown per product, batching, a daily cap and quiet hours.
    'realtime' => [
        // Transitions within this window go out together in one email.
        'batch_minutes' => (int) env('ALERTS_REALTIME_BATCH_MINUTES', 15),
        // At most this many real-time emails per shop per local day; the rest waits for tomorrow.
        'max_per_day' => 3,
        // Local hours [start, end) when real-time emails may go out; outside them they wait.
        'send_from_hour' => 7,
        'send_until_hour' => 21,
        // A product counts as restocked (and can alert again) only once its stock position is
        // this far above the reorder point: max(min_units, ceil(reorder point x ratio)).
        'rearm_ratio' => 0.2,
        'rearm_min_units' => 2,
    ],
];
