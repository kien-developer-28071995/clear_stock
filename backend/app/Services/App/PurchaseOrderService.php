<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Models\Forecast;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;

/**
 * Growth plan: purchase order (CSV) with everything that should be reordered now, either
 * our own readable file or Shopify's purchase order import format.
 */
class PurchaseOrderService
{
    public const FORMAT_STANDARD = 'standard';

    /**
     * Products > Purchase orders > import in the Shopify admin (no API can create purchase
     * orders). Template: help.shopify.com/cdn/shopifycloud/help-center/csv/purchase_order_template.csv
     */
    public const FORMAT_SHOPIFY = 'shopify';

    public function __construct(private readonly ForecastQueryRepositoryInterface $forecasts) {}

    /**
     * @param  array<int, int>|null  $variantIds  only these products (whatever their reorder date)
     * @return array{filename: string, rows: array<int, array<int, string|int|float|null>>, skipped: int, bom: bool, plain_header: bool}
     */
    public function build(Shop $shop, ?int $supplierId, ?int $locationId = null, ?array $variantIds = null, string $format = self::FORMAT_STANDARD): array
    {
        Entitlements::for($shop)->require(Feature::PurchaseOrders);

        $today = CarbonImmutable::now($shop->timezone)->toDateString();
        $items = $this->forecasts->reorderList($shop, $today, $supplierId, $locationId, $variantIds);

        $supplier = $supplierId !== null ? $items->first()?->variant->supplier?->name : null;
        $location = $locationId !== null ? $items->first()?->location?->name : null;
        $slug = collect([$supplier, $location])->filter()->map(fn ($s) => '-'.str($s)->slug())->implode('');

        if ($format === self::FORMAT_SHOPIFY) {
            return $this->shopifyFormat($items, "shopify-purchase-order{$slug}-{$today}.csv");
        }

        $rows = [['Location', 'Supplier', 'Product', 'SKU', 'In stock', 'Sells per day', 'Runs out', 'Order quantity', 'Unit cost', 'Line total', 'Currency']];
        foreach ($items as $f) {
            /** @var Forecast $f */
            $cost = $f->variant->unit_cost !== null ? (float) $f->variant->unit_cost : null;
            $rows[] = [
                $f->location?->name ?? 'All locations',
                $f->variant->supplier?->name ?? '',
                $f->variant->displayName(),
                $f->variant->sku ?? '',
                $f->current_stock,
                (float) $f->avg_daily_sales,
                $f->stockout_date?->toDateString() ?? '',
                $f->suggested_qty,
                $cost,
                $cost !== null ? round($cost * $f->suggested_qty, 2) : null,
                $shop->currency,
            ];
        }

        return ['filename' => "purchase-order{$slug}-{$today}.csv", 'rows' => $rows, 'skipped' => 0, 'bom' => true, 'plain_header' => false];
    }

    /**
     * Shopify matches each row by SKU and/or barcode: products with neither can't be
     * imported and are left out (counted in `skipped`). Cost is the unit cost in the
     * shop's currency; supplier SKU and tax are left for the merchant.
     *
     * @param  iterable<Forecast>  $items
     */
    private function shopifyFormat(iterable $items, string $filename): array
    {
        $rows = [['SKU', 'Barcode', 'Supplier SKU', 'Quantity', 'Cost', 'Tax']];
        $skipped = 0;
        foreach ($items as $f) {
            if ($f->suggested_qty <= 0) {
                continue;
            }
            if ($f->variant->sku === null && $f->variant->barcode === null) {
                $skipped++;

                continue;
            }
            $rows[] = [
                $f->variant->sku ?? '',
                $f->variant->barcode ?? '',
                '',
                $f->suggested_qty,
                $f->variant->unit_cost !== null ? number_format((float) $f->variant->unit_cost, 2, '.', '') : '',
                '',
            ];
        }

        // No BOM: it would become part of the first header name for Shopify's importer.
        return ['filename' => $filename, 'rows' => $rows, 'skipped' => $skipped, 'bom' => false, 'plain_header' => true];
    }
}
