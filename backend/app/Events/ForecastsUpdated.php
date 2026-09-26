<?php

namespace App\Events;

use App\Models\Shop;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Forecasts of a shop were recomputed (Phase 6 sends alerts on this). */
class ForecastsUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Shop $shop, public readonly array $stats) {}
}
