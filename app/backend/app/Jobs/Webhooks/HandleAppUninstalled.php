<?php

namespace App\Jobs\Webhooks;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Services\ShopLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleAppUninstalled implements ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public function __construct(public readonly string $shopDomain) {}

    public function handle(ShopLifecycleService $lifecycle): void
    {
        $lifecycle->markUninstalled($this->shopDomain);
    }
}
