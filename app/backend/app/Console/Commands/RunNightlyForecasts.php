<?php

namespace App\Console\Commands;

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Support\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Safety net: forecasts normally follow the nightly sync. If a shop's sync
 * failed or did not run, recompute from the data we have at the forecast hour
 * (shop time), so dates like "runs out in 3 days" never go stale.
 */
class RunNightlyForecasts extends Command
{
    protected $signature = 'forecast:nightly';

    protected $description = 'Recompute forecasts that are older than 20 hours (at the forecast hour, shop time)';

    public function handle(): int
    {
        $hour = (int) config('forecast.nightly_hour', 5);
        $queued = 0;

        Shop::query()->whereNull('uninstalled_at')->whereNotNull('access_token')
            ->select(['id', 'timezone', 'forecasted_at'])
            ->chunkById(200, function ($shops) use ($hour, &$queued) {
                foreach ($shops as $shop) {
                    // One shop in a bad state never stops the others.
                    try {
                        $stale = $shop->forecasted_at === null || $shop->forecasted_at->lt(now()->subHours(20));
                        if (CarbonImmutable::now($shop->timezone)->hour === $hour && $stale) {
                            RecomputeForecasts::dispatch($shop->id);
                            $queued++;
                        }
                    } catch (\Throwable $e) {
                        Monitor::caught($e, 'nightly forecast', ['shop_id' => $shop->id]);
                    }
                }
            });

        $this->info("Queued {$queued} forecast run(s).");

        return self::SUCCESS;
    }
}
