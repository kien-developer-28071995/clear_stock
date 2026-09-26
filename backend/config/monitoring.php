<?php

/**
 * Error monitoring: every log record at `level` or above (unhandled exceptions, failed
 * jobs, caught system errors via App\Support\Monitor) is posted to a Slack channel
 * through an incoming webhook. The same error is posted at most once per
 * `throttle_seconds`; the next post says how many were suppressed in between.
 */
return [
    // Slack incoming webhook (https://api.slack.com/messaging/webhooks). Empty = off.
    'slack_webhook_url' => env('MONITORING_SLACK_WEBHOOK_URL'),

    // Business events (install, uninstall, upgrade, downgrade) go to this channel's webhook.
    // Kept apart from errors on purpose. Empty = no event messages.
    'events_slack_webhook_url' => env('MONITORING_SLACK_EVENTS_WEBHOOK_URL'),

    // Lowest level sent to Slack: error (default), warning to also get handled/expected problems.
    'level' => env('MONITORING_SLACK_LEVEL', 'error'),

    'throttle_seconds' => (int) env('MONITORING_THROTTLE_SECONDS', 600),

    // Shown in every message, e.g. production / staging / local.
    'environment' => env('MONITORING_ENVIRONMENT') ?: env('APP_ENV', 'production'),

    // Stack frames shown (application frames first).
    'trace_lines' => 8,
];
