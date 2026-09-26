<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Exceptions\PlanRequiredException;
use App\Models\Forecast;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;

/** Growth plan: purchase order (CSV) with everything that should be reordered now. */
class PurchaseOrderService
{
    public function __construct(private readonly ForecastQueryRepositoryInterface $forecasts) {}

    /** @return array{filename: string, rows: array<int, array<int, string|int|float|null>>} */
    /** @param array<int, int>|null $variantIds only these products (whatever their reorder date) */
    public function build(Shop $shop, ?int $supplierId, ?int $locationId = null, ?array $variantIds = null): array
    {
        if (! Entitlements::for($shop)->has(Feature::PurchaseOrders)) {
            throw new PlanRequiredException(Feature::PurchaseOrders);
        }

        $today = CarbonImmutable::now($shop->timezone)->toDateString();
        $items = $this->forecasts->reorderList($shop, $today, $supplierId, $locationId, $variantIds);

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

        $supplier = $supplierId !== null ? $items->first()?->variant->supplier?->name : null;
        $location = $locationId !== null ? $items->first()?->location?->name : null;
        $slug = collect([$supplier, $location])->filter()->map(fn ($s) => '-'.str($s)->slug())->implode('');

        return ['filename' => "purchase-order{$slug}-{$today}.csv", 'rows' => $rows];
    }
}
