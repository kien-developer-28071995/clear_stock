<?php

namespace App\Jobs\Sync;

use App\Services\Sync\SyncService;

/** Downloads bulk results and imports them. Idempotent, so retries are safe. */
class ProcessSyncRun extends SyncJob
{
    public int $tries = 3;

    public int $timeout = 3000;

    protected function run(SyncService $sync): void
    {
        $sync->process($this->runId);
    }
}
