<?php

namespace App\Listeners;

use App\Enums\SyncType;
use App\Events\ShopInstalled;
use App\Services\Sync\SyncService;

/** Kick off the first sync right after install so the merchant sees data within minutes. */
class StartInitialSync
{
    public function __construct(private readonly SyncService $sync) {}

    public function handle(ShopInstalled $event): void
    {
        $this->sync->start($event->shop, SyncType::Initial);
    }
}
