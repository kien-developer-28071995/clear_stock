<?php

namespace App\Events;

use App\Models\Shop;
use App\Models\SyncRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A sync run finished successfully (Phase 4 recomputes forecasts on this). */
class ShopSynced
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Shop $shop, public readonly SyncRun $run) {}
}
