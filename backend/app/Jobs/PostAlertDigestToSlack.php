<?php

namespace App\Jobs;

use App\Monitoring\SlackNotifier;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Posts the reorder digest to the merchant's Slack channel. The webhook URL is read from the
 * shop's alert settings here, so the secret never sits in the queue payload.
 */
class PostAlertDigestToSlack implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $shopId, public readonly array $payload) {}

    public function handle(SlackNotifier $slack, ShopRepositoryInterface $shops, AlertSettingRepositoryInterface $settings): void
    {
        $shop = $shops->findById($this->shopId);
        $url = $shop?->isInstalled() ? $settings->forShop($shop)?->slackUrl() : null;
        if ($url !== null && ! $slack->post($url, $this->payload)) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
