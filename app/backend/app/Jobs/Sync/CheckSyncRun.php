<?php

namespace App\Jobs\Sync;

use App\Services\Sync\SyncService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * Polls the run's bulk operations; re-queues itself until they finish.
 * Unique until processing starts, so the webhook-triggered check and the next
 * scheduled poll never pile up, while the job can still re-queue itself.
 */
class CheckSyncRun extends SyncJob implements ShouldBeUniqueUntilProcessing
{
    public int $timeout = 60;

    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    protected function run(SyncService $sync): void
    {
        $sync->check($this->runId);
    }
}
