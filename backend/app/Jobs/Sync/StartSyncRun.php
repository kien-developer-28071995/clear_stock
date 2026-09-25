<?php

namespace App\Jobs\Sync;

use App\Services\Sync\SyncService;

class StartSyncRun extends SyncJob
{
    public int $timeout = 120;

    protected function run(SyncService $sync): void
    {
        $sync->submit($this->runId);
    }
}
