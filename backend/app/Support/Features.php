<?php

namespace App\Support;

use App\Enums\Feature;

/**
 * App-wide feature switches (config/features.php). Entitlements asks this first, so a
 * switched-off feature is off for every shop whatever its plan. Features without a
 * switch (forecasts, explanations, bundles, alerts) are always on.
 */
final class Features
{
    /** Plan features and the switch that controls each. */
    private const SWITCH = [
        Feature::WhatIf->value => 'what_if',
        Feature::ReferenceProducts->value => 'reference_products',
        Feature::Abc->value => 'abc',
        Feature::SpikeFilter->value => 'spike_filter',
        Feature::LostSales->value => 'lost_sales',
        Feature::PurchasePlan->value => 'purchase_plan',
        Feature::PurchaseOrders->value => 'purchase_orders',
        Feature::SupplierEmails->value => 'supplier_emails',
        Feature::SupplierAutoEmail->value => 'supplier_emails',
        Feature::Locations->value => 'locations',
        Feature::Transfers->value => 'transfers',
        Feature::RealtimeAlerts->value => 'realtime_alerts',
        Feature::FlowTriggers->value => 'flow_triggers',
    ];

    /** A switch that only works with another one on. */
    private const REQUIRES = [
        'transfers' => 'locations',
    ];

    public static function enabled(Feature $feature): bool
    {
        $switch = self::SWITCH[$feature->value] ?? null;

        return $switch === null || self::switchOn($switch);
    }

    /** @return array<string, bool> every switch and its effective state (dependencies applied) */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(config('features', [])) as $switch) {
            $out[$switch] = self::switchOn($switch);
        }

        return $out;
    }

    /** @return array<int, string> switches that are on but have no effect because what they need is off */
    public static function inactiveBecauseOfDependencies(): array
    {
        return array_keys(array_filter(self::REQUIRES, fn ($needs, $switch) => (bool) config("features.{$switch}", true) && ! self::switchOn($needs), ARRAY_FILTER_USE_BOTH));
    }

    private static function switchOn(string $switch): bool
    {
        if (! (bool) config("features.{$switch}", true)) {
            return false;
        }
        $needs = self::REQUIRES[$switch] ?? null;

        return $needs === null || self::switchOn($needs);
    }
}
