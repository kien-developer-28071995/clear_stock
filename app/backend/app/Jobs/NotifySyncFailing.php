<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Sync\SyncFailureNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** After a failed sync: email the merchant if syncing keeps failing (once per streak). */
class NotifySyncFailing implements ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public function __construct(public readonly int $shopId) {}

    public function handle(SyncFailureNotifier $notifier, ShopRepositoryInterface $shops): void
    {
        if ($shop = $shops->findById($this->shopId)) {
            $notifier->notifyIfFailing($shop);
        }
    }
}
