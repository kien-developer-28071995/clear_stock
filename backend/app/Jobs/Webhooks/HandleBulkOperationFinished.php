<?php

namespace App\Jobs\Webhooks;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Services\Sync\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleBulkOperationFinished implements ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public function __construct(public readonly string $shopDomain, public readonly string $operationId) {}

    public function handle(SyncService $sync): void
    {
        $sync->onBulkOperationFinished($this->shopDomain, $this->operationId);
    }
}
