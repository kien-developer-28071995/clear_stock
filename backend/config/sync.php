<?php

return [
    // Queue connection for sync jobs (Horizon supervisor "sync-supervisor").
    'queue_connection' => env('SYNC_QUEUE_CONNECTION', 'redis-long'),

    // First sync after install: days of order history (also feeds the seasonality factor).
    'initial_days' => (int) env('SYNC_INITIAL_DAYS', 365),

    // Nightly sync re-aggregates this many recent days (catches late refunds/cancellations).
    'nightly_window_days' => (int) env('SYNC_NIGHTLY_WINDOW_DAYS', 30),

    // Local hour (shop timezone) at which the nightly sync starts.
    'nightly_hour' => (int) env('SYNC_NIGHTLY_HOUR', 2),

    // ISO weekday (1 = Monday) on which the nightly run refreshes the whole catalog
    // instead of only variants updated since the last sync.
    'full_catalog_weekday' => 1,

    // A run still "running" after this many hours is marked failed.
    'stuck_after_hours' => 6,

    // Finished sync_runs rows are kept this long for troubleshooting.
    'keep_runs_days' => 30,

    // Minimum minutes between two manual syncs.
    'manual_cooldown_minutes' => 5,
];
