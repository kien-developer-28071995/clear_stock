<?php

namespace App\Enums;

enum PlanInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    /** Billing API AppPricingInterval value. */
    public function shopifyInterval(): string
    {
        return $this === self::Monthly ? 'EVERY_30_DAYS' : 'ANNUAL';
    }
}
