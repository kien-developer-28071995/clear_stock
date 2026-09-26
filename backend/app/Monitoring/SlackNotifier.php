<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Posts an ErrorAlert to the Slack incoming webhook. Never throws. */
class SlackNotifier
{
    public function send(ErrorAlert $alert, int $suppressed = 0): bool
    {
        $url = config('monitoring.slack_webhook_url');
        if (! $url) {
            return false;
        }

        try {
            return Http::timeout(5)->connectTimeout(3)->post($url, $this->payload($alert, $suppressed))->successful();
        } catch (Throwable $e) {
            // Must not log at error level here: that would be reported to Slack again.
            error_log('[monitoring] Slack post failed: '.$e->getMessage());

            return false;
        }
    }

    /** Slack Block Kit message. */
    public function payload(ErrorAlert $alert, int $suppressed = 0): array
    {
        $env = config('monitoring.environment');
        $icon = in_array($alert->level, ['emergency', 'alert', 'critical'], true) ? ':fire:' : ($alert->level === 'error' ? ':rotating_light:' : ':warning:');
        $title = sprintf('%s [%s] %s · %s', $icon, $env, strtoupper($alert->level), config('app.name'));

        $fields = array_filter([
            $alert->exceptionClass ? "*Exception*\n`".class_basename($alert->exceptionClass).'`' : null,
            $alert->location ? "*Where*\n`{$alert->location}`" : null,
            ...array_map(fn ($k, $v) => '*'.ucfirst(str_replace('_', ' ', $k))."*\n".$v, array_keys($alert->context), $alert->context),
            $suppressed > 0 ? "*Repeated*\n{$suppressed}× since the last alert" : null,
        ]);

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => mb_substr($title, 0, 150), 'emoji' => true]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '```'.$alert->message.'```']],
        ];
        foreach (array_chunk(array_values($fields), 10) as $chunk) { // Slack allows 10 fields per section
            $blocks[] = ['type' => 'section', 'fields' => array_map(fn ($f) => ['type' => 'mrkdwn', 'text' => mb_substr($f, 0, 1900)], $chunk)];
        }
        if ($alert->trace !== []) {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr("*Trace*\n```".implode("\n", $alert->trace).'```', 0, 2900)]];
        }
        $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => now()->utc()->toDateTimeString().' UTC · '.gethostname()]]];

        return ['text' => "{$title}: {$alert->message}", 'blocks' => $blocks];
    }
}
