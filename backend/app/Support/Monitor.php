<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * For try/catch blocks that handle an error and carry on: the error is still logged
 * with its exception (and so reaches Slack at error level). Use `expected()` for
 * conditions that are normal in production (merchant uninstalled, optional data
 * unavailable): logged as warnings, sent to Slack only with MONITORING_SLACK_LEVEL=warning.
 */
final class Monitor
{
    /** A system error that was handled but should be looked at. */
    public static function caught(Throwable $e, string $where, array $context = []): void
    {
        Log::error("Caught in {$where}: ".$e->getMessage(), ['exception' => $e, 'where' => $where] + $context);
    }

    /** A known, recoverable condition. */
    public static function expected(Throwable $e, string $where, array $context = []): void
    {
        Log::warning("Handled in {$where}: ".$e->getMessage(), ['exception' => $e, 'where' => $where] + $context);
    }
}
