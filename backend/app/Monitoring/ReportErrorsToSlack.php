<?php

namespace App\Monitoring;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Monolog\Level;
use Throwable;

/**
 * Listens to every log record, whatever the log channel: records at the configured
 * level or above become Slack alerts. Duplicates are throttled per fingerprint, and
 * the post happens after the HTTP response is sent (immediately in queue workers
 * and commands), so a slow Slack never slows the app down.
 */
class ReportErrorsToSlack
{
    /** Set on log context to keep a record out of Slack. */
    public const SKIP = 'monitoring_skip';

    private static bool $reporting = false;

    /** @var array<string, int> fallback throttle when the cache itself is down */
    private static array $recent = [];

    public function __construct(private readonly SlackNotifier $slack) {}

    public function handle(MessageLogged $event): void
    {
        if (self::$reporting || ! config('monitoring.slack_webhook_url') || ! empty($event->context[self::SKIP])) {
            return;
        }
        if (Level::fromName($event->level)->isLowerThan(Level::fromName(config('monitoring.level', 'error')))) {
            return;
        }

        self::$reporting = true; // anything logged while building the alert must not loop back here
        try {
            $alert = ErrorAlert::fromLog($event->level, (string) $event->message, $event->context + $this->ambientContext());
            $suppressed = $this->throttle($alert->fingerprint);
            if ($suppressed === null) {
                return;
            }

            $send = fn () => $this->slack->send($alert, $suppressed);
            app()->runningInConsole() ? $send() : app()->terminating($send);
        } catch (Throwable $e) {
            error_log('[monitoring] could not report error: '.$e->getMessage());
        } finally {
            self::$reporting = false;
        }
    }

    /**
     * Null = seen recently (counted, not sent). Otherwise the number of repeats
     * suppressed since the last alert for this fingerprint.
     */
    private function throttle(string $fingerprint): ?int
    {
        $ttl = (int) config('monitoring.throttle_seconds', 600);
        try {
            if (! Cache::add("monitoring:alert:{$fingerprint}", true, $ttl)) {
                Cache::add("monitoring:repeat:{$fingerprint}", 0, $ttl * 6);
                Cache::increment("monitoring:repeat:{$fingerprint}");

                return null;
            }

            return (int) Cache::pull("monitoring:repeat:{$fingerprint}", 0);
        } catch (Throwable) {
            // Cache down (often the very error being reported): throttle in this process only.
            if ((self::$recent[$fingerprint] ?? 0) > time() - $ttl) {
                return null;
            }
            self::$recent[$fingerprint] = time();

            return 0;
        }
    }

    /** Shop / job / command / request, set by middleware and queue hooks through Laravel Context. */
    private function ambientContext(): array
    {
        $context = Context::all();
        $request = app()->bound('request') ? request() : null;
        if ($request?->route() !== null) {
            // Path only: query strings can carry id_token / hmac.
            $context['request'] ??= $request->method().' /'.ltrim($request->path(), '/');
        }

        return $context;
    }
}
