<?php

namespace App\Services\Forecast;

use App\Enums\OverrideField;
use Carbon\CarbonImmutable;

/**
 * Everything the calculator needs for one variant. Built from the database by
 * ForecastInputBuilder, or by hand in tests.
 */
final readonly class ForecastInput
{
    /**
     * @param  array<string, array{sold: int, returned: int, in_stock: bool}>  $days  date => sales; missing dates = no sales, in stock
     * @param  array<int, array{name: string, quantity: int, days: array<string, int>}>  $bundles  manual bundles containing this variant: bundle variant id => units of this variant per bundle + bundle net sales per date
     * @param  array<string, array{value: float, note: ?string, expires_at: ?string}>  $overrides  OverrideField value => override
     */
    public function __construct(
        public int $variantId,
        public CarbonImmutable $asOf,           // today in the shop timezone; history ends yesterday
        public string $coverageStart,           // first date with trustworthy data (variant creation / sync window)
        public int $currentStock,
        public array $days,
        public int $shopLeadTimeDays,
        public int $shopSafetyDays,
        public ?int $variantLeadTimeDays = null,
        public ?int $variantSafetyDays = null,
        public ?string $supplierName = null,
        public ?int $supplierLeadTimeDays = null,
        public array $bundles = [],
        public array $overrides = [],
        public int $incomingStock = 0,          // on the way (Shopify "incoming": purchase orders, transfers)
        public ?int $minOrderQty = null,        // supplier minimum order quantity (units)
        public ?int $packSize = null,           // units per case: orders are whole cases
        public ?int $minStock = null,           // manual reorder point (min)
        public ?int $maxStock = null,           // manual order-up-to level (max)
        public ?string $orderRulesSupplier = null, // minimum order / pack size (partly) from this supplier's defaults
    ) {}

    public function override(OverrideField $field): ?array
    {
        return $this->overrides[$field->value] ?? null;
    }
}
