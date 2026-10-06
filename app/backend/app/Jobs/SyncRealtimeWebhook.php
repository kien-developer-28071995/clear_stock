<?php

namespace App\Jobs;

use App\Exceptions\ShopifyReauthorizeException;
use App\Jobs\Concerns\RetriesWithBackoff;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\App\RealtimeAlertService;
use App\Support\Monitor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Subscribe / unsubscribe the shop's inventory webhook after a settings or plan change (and nightly). */
class SyncRealtimeWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $shopId) {}

    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    public function handle(RealtimeAlertService $alerts, ShopRepositoryInterface $shops): void
    {
        $shop = $shops->findById($this->shopId);
        if ($shop === null) {
            return;
        }

        try {
            $alerts->syncSubscription($shop);
        } catch (ShopifyReauthorizeException $e) {
            Monitor::expected($e, 'real-time webhook sync', ['shop' => $shop->domain]);
        }
    }
}
