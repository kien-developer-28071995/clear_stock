<?php

namespace App\Jobs\Forecast;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Forecast\ForecastService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Recompute a shop's forecasts. Runs on the long "sync" queue (large catalogs take a while). */
class RecomputeForecasts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public int $timeout = 1800;

    /** @param array<int, int>|null $variantIds null = all variants */
    public function __construct(public readonly int $shopId, public readonly ?array $variantIds = null)
    {
        $this->onConnection(config('sync.queue_connection'))->onQueue('sync');
    }

    /** Several triggers in a row (sync finished + settings changed) collapse into one full run. */
    public function uniqueId(): string
    {
        return $this->shopId.':'.($this->variantIds === null ? 'all' : md5(implode(',', $this->variantIds)));
    }

    public function handle(ForecastService $forecasts, ShopRepositoryInterface $shops): void
    {
        $shop = $shops->findById($this->shopId);
        if ($shop === null || ! $shop->isInstalled()) {
            return;
        }

        $forecasts->runForShop($shop, $this->variantIds);
    }
}
