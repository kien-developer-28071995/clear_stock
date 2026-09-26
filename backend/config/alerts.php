<?php

return [
    // Local hour (shop timezone) from which the digest can go out; forecasts are ready by then.
    'send_hour' => 8,

    // A product already included in a digest is only reported as "new" again after this many
    // days, or sooner if it went from "reorder soon" to "out of stock". No daily repeats.
    'realert_days' => 7,

    // Products listed in one email (the rest are summarised as "and N more").
    'max_items' => 20,

    // Don't email from forecasts older than this (e.g. the nightly sync kept failing).
    'max_forecast_age_hours' => 36,
];
