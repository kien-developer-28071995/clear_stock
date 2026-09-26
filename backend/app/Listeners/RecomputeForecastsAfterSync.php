<?php

namespace App\Listeners;

use App\Events\ShopSynced;
use App\Jobs\Forecast\RecomputeForecasts;

/** Fresh data in => fresh forecasts out (this is what makes forecasts run nightly). */
class RecomputeForecastsAfterSync
{
    public function handle(ShopSynced $event): void
    {
        RecomputeForecasts::dispatch($event->shop->id);
    }
}
