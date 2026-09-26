<?php

namespace App\Console\Commands;

use App\Enums\SyncType;
use App\Models\Shop;
use App\Services\Sync\SyncService;
use Illuminate\Console\Command;

/** Support/dev tool: start a sync from the CLI (queued, like "Sync now"). */
class SyncRun extends Command
{
    protected $signature = 'sync:run {--shop= : Shop domain (default: first installed shop)} {--full : Re-import the whole 400-day window and catalog}';

    protected $description = 'Start a sync for a shop (use --full after historical orders were imported)';

    public function handle(SyncService $sync): int
    {
        $shop = $this->option('shop')
            ? Shop::firstWhere('domain', $this->option('shop'))
            : Shop::query()->whereNull('uninstalled_at')->first();

        if ($shop === null) {
            $this->error('Shop not found.');

            return self::FAILURE;
        }

        $run = $sync->start($shop, SyncType::Manual, full: (bool) $this->option('full'));
        $this->info("Sync run #{$run->id} ({$run->type->value}) from {$run->window_start->toDateString()} is {$run->status->value}. Watch: make logs s=horizon");

        return self::SUCCESS;
    }
}
