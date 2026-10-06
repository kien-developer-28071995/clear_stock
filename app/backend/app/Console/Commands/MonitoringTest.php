<?php

namespace App\Console\Commands;

use App\Monitoring\ErrorAlert;
use App\Monitoring\SlackNotifier;
use Illuminate\Console\Command;
use RuntimeException;

/** Sends a test alert to the monitoring Slack channel (bypasses throttling). */
class MonitoringTest extends Command
{
    protected $signature = 'monitoring:test';

    protected $description = 'Send a test error alert to the monitoring Slack channel';

    public function handle(SlackNotifier $slack): int
    {
        if (! config('monitoring.slack_webhook_url')) {
            $this->error('MONITORING_SLACK_WEBHOOK_URL is not set.');

            return self::FAILURE;
        }

        $e = new RuntimeException('Test alert from monitoring:test. If you can read this, error monitoring works.');
        $sent = $slack->send(ErrorAlert::fromLog('error', $e->getMessage(), ['exception' => $e, 'command' => 'monitoring:test']));

        $sent ? $this->info('Sent. Check the Slack channel.') : $this->error('Slack rejected the message (see the log for details).');

        return $sent ? self::SUCCESS : self::FAILURE;
    }
}
