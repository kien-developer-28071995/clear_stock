<?php

namespace App\Jobs;

use App\Monitoring\SlackNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Posts a business-event message to the events Slack channel (queued: never slows installs or billing). */
class PostShopEventToSlack implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly array $payload) {}

    public function handle(SlackNotifier $slack): void
    {
        $url = config('monitoring.events_slack_webhook_url');
        if ($url && ! $slack->post($url, $this->payload)) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 120);
        }
    }
}
