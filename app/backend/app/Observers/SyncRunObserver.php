<?php

namespace App\Observers;

use App\Models\SyncRun;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class SyncRunObserver
{
    public function saved(SyncRun $run): void
    {
        Cache::forget(CacheKeys::latestSyncRun($run->shop_id));
    }

    public function deleted(SyncRun $run): void
    {
        Cache::forget(CacheKeys::latestSyncRun($run->shop_id));
    }
}
