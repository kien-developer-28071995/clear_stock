<?php

namespace App\Events;

use App\Enums\Plan;
use App\Models\Shop;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShopUninstalled
{
    use Dispatchable, SerializesModels;

    /** @param ?Plan $plan the plan the shop was on (the shop itself is already back on Free) */
    public function __construct(public readonly Shop $shop, public readonly ?Plan $plan = null) {}
}
