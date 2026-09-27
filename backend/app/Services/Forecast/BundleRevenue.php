<?php

namespace App\Services\Forecast;

/**
 * Pure: a bundle's revenue credited to the products inside it, for ABC classes. A bundle that is
 * not itself a tracked product (the usual virtual bundle) sells its components, so its revenue is
 * split over them by quantity x price (or by quantity alone when a component has no price).
 */
final class BundleRevenue
{
    /**
     * @param  array<int, float>  $revenue  tracked variant id => own revenue
     * @param  array<int, array<int, int>>  $bundles  bundle id => component id => units per bundle
     * @param  array<int, float>  $bundleRevenue  bundle id => revenue of the bundle
     * @param  array<int, ?float>  $prices  component id => current price
     * @param  array<int, int>  $tracked  ids that are classified (only they receive a share)
     * @return array<int, float> revenue with bundle shares added
     */
    public static function attribute(array $revenue, array $bundles, array $bundleRevenue, array $prices, array $tracked): array
    {
        $tracked = array_flip($tracked);
        foreach ($bundles as $bundleId => $components) {
            $amount = (float) ($bundleRevenue[$bundleId] ?? 0);
            if ($amount <= 0 || isset($tracked[$bundleId]) || $components === []) {
                continue; // a tracked bundle is classified on its own sales
            }
            $byPrice = array_filter($components, fn ($q, $id) => ($prices[$id] ?? null) === null, ARRAY_FILTER_USE_BOTH) === [];
            $weights = [];
            foreach ($components as $id => $qty) {
                $weights[$id] = $byPrice ? $qty * (float) $prices[$id] : (float) $qty;
            }
            $total = array_sum($weights);
            if ($total <= 0) {
                continue;
            }
            foreach ($weights as $id => $w) {
                if (isset($tracked[$id])) {
                    $revenue[$id] = ($revenue[$id] ?? 0.0) + $amount * $w / $total;
                }
            }
        }

        return $revenue;
    }
}
