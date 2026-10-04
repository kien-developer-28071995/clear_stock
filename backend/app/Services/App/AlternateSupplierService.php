<?php

namespace App\Services\App;

use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Other suppliers of a product (every plan). The forecast uses the main supplier; an alternate
 * keeps its own cost, lead time and product code so the merchant can switch in one step when the
 * main one is out of stock or late. Switching makes the old main supplier an alternate.
 */
class AlternateSupplierService
{
    private const MAX = 5;

    public function __construct(
        private readonly VariantRepositoryInterface $variants,
        private readonly ForecastService $engine,
    ) {}

    /** @return array<int, array{supplier_id: int, name: string, unit_cost: ?float, lead_time_days: ?int, supplier_sku: ?string}> */
    public function list(Shop $shop, int $variantId): array
    {
        return DB::table('variant_suppliers')->join('suppliers', 'suppliers.id', '=', 'variant_suppliers.supplier_id')
            ->where('variant_suppliers.shop_id', $shop->id)->where('variant_suppliers.variant_id', $variantId)
            ->orderBy('suppliers.name')
            ->get(['variant_suppliers.supplier_id', 'suppliers.name', 'variant_suppliers.unit_cost', 'variant_suppliers.lead_time_days', 'variant_suppliers.supplier_sku'])
            ->map(fn ($r) => [
                'supplier_id' => (int) $r->supplier_id, 'name' => $r->name,
                'unit_cost' => $r->unit_cost !== null ? (float) $r->unit_cost : null,
                'lead_time_days' => $r->lead_time_days !== null ? (int) $r->lead_time_days : null,
                'supplier_sku' => $r->supplier_sku,
            ])->all();
    }

    /** @param array<int, array{supplier_id: int, unit_cost?: ?float, lead_time_days?: ?int, supplier_sku?: ?string}> $suppliers replaces the list */
    public function replace(Shop $shop, Variant $variant, array $suppliers): array
    {
        $own = DB::table('suppliers')->where('shop_id', $shop->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rows = [];
        foreach ($suppliers as $s) {
            $id = (int) $s['supplier_id'];
            if ($id === $variant->supplier_id) {
                throw ValidationException::withMessages(['suppliers' => 'alternate_is_main']);
            }
            if (in_array($id, $own, true)) {
                $rows[$id] = [
                    'shop_id' => $shop->id, 'variant_id' => $variant->id, 'supplier_id' => $id,
                    'unit_cost' => $s['unit_cost'] ?? null, 'lead_time_days' => $s['lead_time_days'] ?? null, 'supplier_sku' => $s['supplier_sku'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        if (count($rows) > self::MAX) {
            throw ValidationException::withMessages(['suppliers' => 'too_many_suppliers']);
        }
        DB::transaction(function () use ($shop, $variant, $rows) {
            DB::table('variant_suppliers')->where('shop_id', $shop->id)->where('variant_id', $variant->id)->delete();
            DB::table('variant_suppliers')->insert(array_values($rows));
        });

        return $this->list($shop, $variant->id);
    }

    /**
     * The alternate becomes the product's supplier, with its cost, lead time and code; the supplier
     * it replaces is kept as an alternate with the values the product had.
     */
    public function makeMain(Shop $shop, Variant $variant, int $supplierId): Variant
    {
        $alternate = DB::table('variant_suppliers')->where('shop_id', $shop->id)->where('variant_id', $variant->id)->where('supplier_id', $supplierId)->first();
        if ($alternate === null) {
            throw ValidationException::withMessages(['supplier_id' => 'not_an_alternate']);
        }

        DB::transaction(function () use ($shop, $variant, $alternate) {
            DB::table('variant_suppliers')->where('id', $alternate->id)->delete();
            if ($variant->supplier_id !== null) {
                DB::table('variant_suppliers')->insert([
                    'shop_id' => $shop->id, 'variant_id' => $variant->id, 'supplier_id' => $variant->supplier_id,
                    'unit_cost' => $variant->cost_override, 'lead_time_days' => $variant->lead_time_override, 'supplier_sku' => $variant->supplier_sku,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->variants->updateSettings($variant, [
                'supplier_id' => (int) $alternate->supplier_id,
                'lead_time_override' => $alternate->lead_time_days !== null ? (int) $alternate->lead_time_days : null,
                'supplier_sku' => $alternate->supplier_sku,
            ]);
            // The cost entered for this supplier wins over Shopify's cost, like any cost entered in the app.
            // (Two statements: whether a SET sees the row's new cost_override differs between MySQL and SQLite.)
            DB::table('variants')->where('id', $variant->id)->update(['cost_override' => $alternate->unit_cost, 'landed_cost_applied' => false]);
            DB::table('variants')->where('id', $variant->id)->update(['unit_cost' => DB::raw('COALESCE(cost_override, shopify_unit_cost)')]);
        });
        $this->engine->runForShop($shop, [$variant->id]);

        return $variant->refresh();
    }
}
