<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileBilling;
use App\Models\Shop;
use Illuminate\Console\Command;

/** Daily: re-read every installed shop's subscription from Shopify, in case a billing webhook was missed. */
class ReconcileBillingCommand extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Match every installed shop plan to its Shopify subscription (missed-webhook safety net)';

    public function handle(): int
    {
        $count = 0;
        Shop::query()->whereNull('uninstalled_at')->whereNotNull('access_token')->select('id')
            ->chunkById(500, function ($shops) use (&$count) {
                foreach ($shops as $shop) {
                    ReconcileBilling::dispatch($shop->id);
                    $count++;
                }
            });

        $this->info("Queued {$count} shop(s).");

        return self::SUCCESS;
    }
}
