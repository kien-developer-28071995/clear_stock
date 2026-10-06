<?php

namespace App\Events;

use App\Enums\Plan;
use App\Enums\PlanInterval;
use App\Models\Shop;
use Illuminate\Foundation\Events\Dispatchable;

/** The shop's plan or billing interval changed (in the app, by webhook or by daily reconciliation). */
class PlanChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Shop $shop,
        public readonly ?Plan $from,
        public readonly ?PlanInterval $fromInterval,
        public readonly Plan $to,
        public readonly ?PlanInterval $toInterval,
    ) {}
}
