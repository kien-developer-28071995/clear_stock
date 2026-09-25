<?php

namespace App\Jobs\Webhooks;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Services\ShopLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sent 48h after uninstall: erase everything we hold for the shop. */
class HandleShopRedact implements ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public int $timeout = 600;

    public function __construct(public readonly string $shopDomain) {}

    public function handle(ShopLifecycleService $lifecycle): void
    {
        $lifecycle->redactShop($this->shopDomain);
    }
}
