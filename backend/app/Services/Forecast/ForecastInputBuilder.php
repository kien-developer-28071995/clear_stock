<?php

namespace App\Services\Forecast;

use App\Enums\Feature;
use App\Enums\OverrideField;
use App\Models\Shop;
use App\Repositories\Contracts\DailySalesRepositoryInterface;
use App\Repositories\Contracts\ForecastRepositoryInterface;
use App\Repositories\Contracts\LocationSalesRepositoryInterface;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;

/** Loads everything the calculator needs for a batch of variants (a few queries per batch, not per variant). */
class ForecastInputBuilder
{
    public function __construct(
        private readonly ForecastRepositoryInterface $forecasts,
        private readonly DailySalesRepositoryInterface $sales,
        private readonly LocationSalesRepositoryInterface $locationSales,
    ) {}

    /**
     * One input per (variant, location) that has stock or sales there. Lead time and
     * safety settings/overrides apply as for the combined forecast; a sales-rate
     * override is store-wide and is not split across locations.
     *
     * @param  array<int, int>  $variantIds
     * @param  array<int, array<int, int>>  $stock  variant => location => available
     * @param  array<int, ForecastInput>  $combined  the combined inputs of these variants (settings reused)
     * @param  array<int, array<int, int>>  $incoming  variant => location => on the way
     * @return array<int, array{location_id: int, input: ForecastInput}>
     */
    public function buildForLocations(Shop $shop, array $variantIds, array $stock, CarbonImmutable $asOf, array $combined, array $incoming = []): array
    {
        $historyStart = $asOf->subDays((int) config('forecast.history_days'))->toDateString();
        $bundles = Entitlements::for($shop)->has(Feature::Bundles)
            ? $this->forecasts->manualBundlesContaining($shop, $variantIds)
            : [];
        $bundleIds = array_values(array_unique(array_merge([], ...array_map('array_keys', $bundles))));
        $rows = $this->locationSales->rowsBetween($shop, array_values(array_unique([...$variantIds, ...$bundleIds])), $historyStart, $asOf->subDay()->toDateString());

        $out = [];
        foreach ($variantIds as $id) {
            $base = $combined[$id] ?? null;
            if ($base === null) {
                continue;
            }
            $locations = array_unique([...array_keys($stock[$id] ?? []), ...array_keys($rows[$id] ?? []), ...array_keys($incoming[$id] ?? [])]);
            sort($locations);

            foreach ($locations as $locationId) {
                $bundleInputs = [];
                foreach ($bundles[$id] ?? [] as $bundleId => $b) {
                    $bundleInputs[$bundleId] = [
                        'name' => $b['name'],
                        'quantity' => $b['quantity'],
                        'days' => array_map(fn ($r) => $r['sold'], $rows[$bundleId][$locationId] ?? []),
                    ];
                }

                $out[] = ['location_id' => $locationId, 'input' => new ForecastInput(
                    variantId: $id,
                    asOf: $asOf,
                    coverageStart: $base->coverageStart,
                    currentStock: $stock[$id][$locationId] ?? 0,
                    days: array_map(fn ($r) => ['sold' => $r['sold'], 'returned' => 0, 'in_stock' => $r['in_stock']], $rows[$id][$locationId] ?? []),
                    shopLeadTimeDays: $base->shopLeadTimeDays,
                    shopSafetyDays: $base->shopSafetyDays,
                    variantLeadTimeDays: $base->variantLeadTimeDays,
                    variantSafetyDays: $base->variantSafetyDays,
                    supplierName: $base->supplierName,
                    supplierLeadTimeDays: $base->supplierLeadTimeDays,
                    bundles: $bundleInputs,
                    overrides: array_diff_key($base->overrides, [OverrideField::AvgDailySales->value => true]),
                    incomingStock: $incoming[$id][$locationId] ?? 0,
                    minOrderQty: $base->minOrderQty,
                    packSize: $base->packSize,
                    orderRulesSupplier: $base->orderRulesSupplier,
                )];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $variantIds
     * @param  array<int, int>  $stock  variant id => current stock (all active locations)
     * @param  array<int, int>  $incoming  variant id => on the way (all active locations)
     * @return array<int, ForecastInput>
     */
    public function build(Shop $shop, array $variantIds, array $stock, CarbonImmutable $asOf, array $incoming = []): array
    {
        $historyStart = $asOf->subDays((int) config('forecast.history_days'))->toDateString();
        $yesterday = $asOf->subDay()->toDateString();

        $variants = $this->forecasts->variantsWithSuppliers($shop, $variantIds);
        // Bundle demand is a paid-plan feature.
        $bundles = Entitlements::for($shop)->has(Feature::Bundles)
            ? $this->forecasts->manualBundlesContaining($shop, $variantIds)
            : [];
        $overrides = $this->forecasts->activeOverrides($shop, $variantIds);
        // New products forecast from a similar product (paid plans).
        $references = Entitlements::for($shop)->has(Feature::ReferenceProducts)
            ? $this->forecasts->referenceRates($shop, $variants->pluck('reference_variant_id')->filter()->unique()->values()->all())
            : [];

        $bundleIds = array_values(array_unique(array_merge([], ...array_map('array_keys', $bundles))));
        $rows = $this->sales->rowsBetween($shop, array_values(array_unique([...$variantIds, ...$bundleIds])), $historyStart, $yesterday);

        $inputs = [];
        foreach ($variantIds as $id) {
            $variant = $variants[$id] ?? null;
            if ($variant === null) {
                continue;
            }

            $created = $variant->shopify_created_at?->setTimezone($shop->timezone)->toDateString();
            $days = array_map(fn ($r) => ['sold' => $r['sold'], 'returned' => $r['returned'], 'in_stock' => $r['in_stock']], $rows[$id] ?? []);

            $bundleInputs = [];
            foreach ($bundles[$id] ?? [] as $bundleId => $b) {
                $bundleInputs[$bundleId] = [
                    'name' => $b['name'],
                    'quantity' => $b['quantity'],
                    'days' => array_map(fn ($r) => max(0, $r['sold'] - $r['returned']), $rows[$bundleId] ?? []),
                ];
            }

            $inputs[$id] = new ForecastInput(
                variantId: $id,
                asOf: $asOf,
                coverageStart: $created !== null ? max($historyStart, $created) : $historyStart,
                currentStock: $stock[$id] ?? 0,
                days: $days,
                shopLeadTimeDays: $shop->default_lead_time_days,
                shopSafetyDays: $shop->default_safety_days,
                variantLeadTimeDays: $variant->lead_time_override,
                variantSafetyDays: $variant->safety_days,
                supplierName: $variant->supplier?->name,
                supplierLeadTimeDays: $variant->supplier?->lead_time_days,
                bundles: $bundleInputs,
                overrides: $overrides[$id] ?? [],
                incomingStock: $incoming[$id] ?? 0,
                minOrderQty: $variant->effectiveMinOrderQty(),
                packSize: $variant->effectivePackSize(),
                // Named in the explanation when the rounding comes from the supplier's defaults.
                reference: isset($references[$variant->reference_variant_id]) ? [
                    'variant_id' => $variant->reference_variant_id,
                    'name' => $references[$variant->reference_variant_id]['name'],
                    'avg' => $references[$variant->reference_variant_id]['avg'],
                    'percent' => $variant->reference_percent ?? 100,
                ] : null,
                orderRulesSupplier: ($variant->min_order_qty === null && $variant->supplier?->min_order_qty !== null)
                    || ($variant->pack_size === null && $variant->supplier?->pack_size !== null) ? $variant->supplier->name : null,
                minStock: $variant->min_stock,
                maxStock: $variant->max_stock,
            );
        }

        return $inputs;
    }
}
