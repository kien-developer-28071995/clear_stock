<?php

namespace App\Console\Commands;

use App\Jobs\SyncRealtimeWebhook;
use App\Repositories\Contracts\RealtimeAlertRepositoryInterface;
use Illuminate\Console\Command;

/**
 * Daily reconciliation of the real-time inventory webhooks: Shopify deletes a shop-specific
 * subscription after repeated delivery failures, the app URL can change, and a missed
 * billing webhook could leave one behind after a downgrade.
 */
class SyncRealtimeWebhooks extends Command
{
    protected $signature = 'alerts:realtime-sync';

    protected $description = 'Make sure real-time alert webhooks exist exactly for shops that use them';

    public function handle(RealtimeAlertRepositoryInterface $realtime): int
    {
        $ids = $realtime->shopsToReconcile();
        foreach ($ids as $id) {
            SyncRealtimeWebhook::dispatch($id);
        }
        $this->info('Queued '.count($ids).' shop(s).');

        return self::SUCCESS;
    }
}
