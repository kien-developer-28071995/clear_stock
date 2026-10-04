<?php

namespace App\Services\App;

use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Data health check (every plan): what in the shop's product data makes forecasts or money
 * figures wrong or incomplete, with a count and a few examples per finding. Read-only.
 */
class DataHealthService
{
    private const SAMPLE = 5;

    /** @return array{checked: int, findings: array<int, array{code: string, severity: string, count: int, sample: array<int, array{variant_id: int, name: string, sku: ?string}>}>} */
    public function check(Shop $shop): array
    {
        $tracked = fn (): Builder => Variant::query()->forShop($shop)->where('is_active', true)->where('tracked', true);
        $stock = DB::table('inventory_levels')->join('locations', 'locations.id', '=', 'inventory_levels.location_id')
            ->where('inventory_levels.shop_id', $shop->id)->where('locations.is_active', true)
            ->groupBy('inventory_levels.variant_id')->havingRaw('SUM(inventory_levels.available) < 0')->select('inventory_levels.variant_id');
        $duplicateSkus = DB::table('variants')->where('shop_id', $shop->id)->where('is_active', true)->where('tracked', true)
            ->whereNotNull('sku')->where('sku', '!=', '')->groupBy('sku')->havingRaw('COUNT(*) > 1')->select('sku');

        $checks = [
            // Stock in Shopify is below zero: days left and order sizes start from a wrong number.
            ['negative_stock', 'warning', $tracked()->whereIn('id', $stock)],
            // Shopify does not track the stock: the app cannot forecast it.
            ['not_tracked', 'warning', Variant::query()->forShop($shop)->where('is_active', true)->where('tracked', false)],
            // No unit cost: missing from every money figure (cash tied up, purchase plan, budget).
            ['missing_cost', 'warning', $tracked()->whereNull('unit_cost')],
            // The same SKU on several products: purchase orders and CSV imports match by SKU.
            ['duplicate_sku', 'warning', $tracked()->whereIn('sku', $duplicateSkus)],
            ['missing_sku', 'info', $tracked()->where(fn ($q) => $q->whereNull('sku')->orWhere('sku', ''))],
            // No price: no ABC class or lost-revenue estimate.
            ['missing_price', 'info', $tracked()->whereNull('price')],
            ['no_supplier', 'info', $tracked()->whereNull('supplier_id')->where('discontinued', false)],
            // Lead time from the store default only: neither the product nor its supplier has one.
            ['default_lead_time', 'info', $tracked()->where('discontinued', false)->whereNull('lead_time_override')
                ->where(fn ($q) => $q->whereNull('supplier_id')->orWhereIn('supplier_id', DB::table('suppliers')->where('shop_id', $shop->id)->whereNull('lead_time_days')->select('id')))],
        ];

        $findings = [];
        foreach ($checks as [$code, $severity, $query]) {
            $count = (clone $query)->count();
            if ($count === 0) {
                continue;
            }
            $findings[] = [
                'code' => $code,
                'severity' => $severity,
                'count' => $count,
                'sample' => $query->orderBy('product_title')->orderBy('id')->limit(self::SAMPLE)->get(['id', 'product_title', 'title', 'sku'])
                    ->map(fn (Variant $v) => ['variant_id' => $v->id, 'name' => $v->displayName(), 'sku' => $v->sku])->all(),
            ];
        }

        return [
            'checked' => $tracked()->count(),
            'default_lead_time_days' => $shop->default_lead_time_days,
            'findings' => $findings,
        ];
    }
}
