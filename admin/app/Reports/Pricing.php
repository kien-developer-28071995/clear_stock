<?php

namespace App\Reports;

/** Monthly recurring revenue of a plan, from the list prices (config/report.php). An estimate. */
final class Pricing
{
    public static function mrr(?string $plan, ?string $interval): float
    {
        $prices = config("report.prices.{$plan}");
        if ($prices === null) {
            return 0.0;
        }

        return $interval === 'annual' ? round($prices['annual'] / 12, 2) : (float) $prices['monthly'];
    }
}
