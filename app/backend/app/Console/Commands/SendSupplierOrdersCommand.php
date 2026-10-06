<?php

namespace App\Console\Commands;

use App\Jobs\SendSupplierOrders;
use App\Models\Supplier;
use Illuminate\Console\Command;

/** Hourly: queue automatic supplier purchase order emails for shops with opted-in suppliers. */
class SendSupplierOrdersCommand extends Command
{
    protected $signature = 'suppliers:send-orders';

    protected $description = 'Queue automatic purchase order emails to suppliers (shop time)';

    public function handle(): int
    {
        $shopIds = Supplier::query()->withoutGlobalScopes()->where('auto_email', true)->whereNotNull('email')->distinct()->pluck('shop_id');
        $shopIds->each(fn ($id) => SendSupplierOrders::dispatch((int) $id));
        $this->info("Checked {$shopIds->count()} shop(s).");

        return self::SUCCESS;
    }
}
