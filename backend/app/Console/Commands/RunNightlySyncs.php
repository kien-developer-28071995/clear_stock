<?php

namespace App\Console\Commands;

use App\Enums\SyncType;
use App\Models\Shop;
use App\Services\Sync\SyncService;
use App\Support\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Runs hourly; starts the nightly sync for shops whose local time is the
 * configured hour, so every shop syncs at night in its own timezone.
 */
class RunNightlySyncs extends Command
{
    protected $signature = 'sync:nightly';

    protected $description = 'Start nightly syncs for shops where it is now the nightly hour';

    public function handle(SyncService $sync): int
    {
        $hour = (int) config('sync.nightly_hour');
        $started = 0;

        Shop::query()
            ->whereNull('uninstalled_at')
            ->whereNotNull('access_token')
            ->select(['id', 'domain', 'timezone', 'last_synced_at'])
            ->chunkById(200, function ($shops) use ($sync, $hour, &$started) {
                foreach ($shops as $shop) {
                    // One shop that cannot start (its state, Shopify's answer) never stops the others.
                    try {
                        $local = CarbonImmutable::now($shop->timezone);
                        $syncedToday = $shop->last_synced_at !== null
                            && CarbonImmutable::parse($shop->last_synced_at)->setTimezone($shop->timezone)->isSameDay($local)
                            && $local->hour >= $hour;

                        if ($local->hour === $hour && ! $syncedToday) {
                            $sync->start($shop->fresh(), SyncType::Nightly);
                            $started++;
                        }
                    } catch (\Throwable $e) {
                        Monitor::caught($e, 'nightly sync start', ['shop' => $shop->domain]);
                    }
                }
            });

        $this->info("Started {$started} nightly sync(s).");

        return self::SUCCESS;
    }
}
