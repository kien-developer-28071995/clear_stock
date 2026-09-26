<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\Forecast\ForecastService;
use Illuminate\Console\Command;

class ForecastRun extends Command
{
    protected $signature = 'forecast:run {--shop= : Shop domain (default: all installed shops)}';

    protected $description = 'Recompute forecasts now (synchronously)';

    public function handle(ForecastService $forecasts): int
    {
        $shops = Shop::query()->whereNull('uninstalled_at')
            ->when($this->option('shop'), fn ($q, $domain) => $q->where('domain', $domain))->get();

        foreach ($shops as $shop) {
            $stats = $forecasts->runForShop($shop);
            $this->info("{$shop->domain}: ".json_encode($stats));
        }

        return self::SUCCESS;
    }
}
