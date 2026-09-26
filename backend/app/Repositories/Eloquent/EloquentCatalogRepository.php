<?php

namespace App\Repositories\Eloquent;

use App\Models\BundleComponent;
use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EloquentCatalogRepository implements CatalogRepositoryInterface
{
    private const CHUNK = 500;

    public function upsertLocations(Shop $shop, array $rows): void
    {
        $now = now();
        $rows = array_map(fn ($r) => $r + ['shop_id' => $shop->id, 'created_at' => $now, 'updated_at' => $now], $rows);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('locations')->upsert($chunk, ['shop_id', 'shopify_location_id'], ['name', 'is_active', 'updated_at']);
        }
    }

    public function activeLocationIds(Shop $shop): array
    {
        return DB::table('locations')->where('shop_id', $shop->id)->where('is_active', true)
            ->pluck('id', 'shopify_location_id')->mapWithKeys(fn ($id, $sid) => [(int) $sid => (int) $id])->all();
    }

    public function upsertVariants(Shop $shop, array $rows): void
    {
        $now = now();
        $rows = array_map(fn ($r) => $r + ['shop_id' => $shop->id, 'created_at' => $now, 'updated_at' => $now], $rows);
        // Merchant settings (supplier, lead time, safety days, is_bundle) are never overwritten by the sync.
        $update = ['shopify_product_id', 'inventory_item_id', 'product_title', 'title', 'vendor', 'product_type', 'sku', 'barcode', 'unit_cost', 'price',
            'tracked', 'is_active', 'shopify_created_at', 'updated_at'];

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('variants')->upsert($chunk, ['shop_id', 'shopify_variant_id'], $update);
        }
    }

    public function variantIdMap(Shop $shop): array
    {
        return DB::table('variants')->where('shop_id', $shop->id)
            ->pluck('id', 'shopify_variant_id')->mapWithKeys(fn ($id, $sid) => [(int) $sid => (int) $id])->all();
    }

    public function replaceShopifyBundleComponents(Shop $shop, array $bundleVariantIds, array $components): void
    {
        DB::transaction(function () use ($shop, $bundleVariantIds, $components) {
            foreach (array_chunk($bundleVariantIds, self::CHUNK) as $ids) {
                DB::table('bundle_components')->where('shop_id', $shop->id)
                    ->where('source', BundleComponent::SOURCE_SHOPIFY)
                    ->whereIn('bundle_variant_id', $ids)->delete();
            }

            $now = now();
            $rows = array_map(fn ($c) => $c + [
                'shop_id' => $shop->id, 'source' => BundleComponent::SOURCE_SHOPIFY, 'created_at' => $now, 'updated_at' => $now,
            ], $components);
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                // A manual definition of the same pair wins; Shopify's is then skipped.
                DB::table('bundle_components')->insertOrIgnore($chunk);
            }

            $bundleIds = array_values(array_unique(array_column($components, 'bundle_variant_id')));
            foreach (array_chunk($bundleIds, self::CHUNK) as $ids) {
                DB::table('variants')->where('shop_id', $shop->id)->whereIn('id', $ids)->update(['is_bundle' => true]);
            }
        });
    }

    public function upsertInventoryLevels(Shop $shop, array $rows): void
    {
        $now = now();
        $rows = array_map(fn ($r) => $r + ['shop_id' => $shop->id, 'created_at' => $now, 'updated_at' => $now], $rows);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('inventory_levels')->upsert($chunk, ['variant_id', 'location_id'], ['available', 'incoming', 'updated_at']);
        }
    }

    public function deleteInventoryLevelsNotSeenSince(Shop $shop, Carbon $since): int
    {
        return DB::table('inventory_levels')->where('shop_id', $shop->id)->where('updated_at', '<', $since)->delete();
    }

    public function deactivateVariantsNotIn(Shop $shop, array $variantIds): int
    {
        $present = array_flip($variantIds);
        $missing = DB::table('variants')->where('shop_id', $shop->id)->where('is_active', true)->pluck('id')
            ->reject(fn ($id) => isset($present[$id]))->values()->all();

        foreach (array_chunk($missing, self::CHUNK) as $ids) {
            DB::table('variants')->whereIn('id', $ids)->update(['is_active' => false, 'updated_at' => now()]);
        }

        return count($missing);
    }

    public function updateTracked(Shop $shop, array $rows): void
    {
        foreach ([true, false] as $tracked) {
            $ids = array_column(array_filter($rows, fn ($r) => $r['tracked'] === $tracked), 'variant_id');
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                DB::table('variants')->where('shop_id', $shop->id)->whereIn('id', $chunk)->update(['tracked' => $tracked]);
            }
        }
    }

    public function stockByVariant(Shop $shop): array
    {
        return $this->sumByVariant($shop, 'available');
    }

    public function stockByVariantAndLocation(Shop $shop): array
    {
        return $this->byVariantAndLocation($shop, 'available');
    }

    public function incomingByVariant(Shop $shop): array
    {
        return array_filter($this->sumByVariant($shop, 'incoming'));
    }

    public function incomingByVariantAndLocation(Shop $shop): array
    {
        return array_filter(array_map('array_filter', $this->byVariantAndLocation($shop, 'incoming')));
    }

    /** @return array<int, int> */
    private function sumByVariant(Shop $shop, string $column): array
    {
        return DB::table('inventory_levels')
            ->join('locations', 'locations.id', '=', 'inventory_levels.location_id')
            ->where('inventory_levels.shop_id', $shop->id)
            ->where('locations.is_active', true)
            ->groupBy('inventory_levels.variant_id')
            ->selectRaw("inventory_levels.variant_id as variant_id, SUM(inventory_levels.{$column}) as qty")
            ->pluck('qty', 'variant_id')->mapWithKeys(fn ($s, $id) => [(int) $id => (int) $s])->all();
    }

    /** @return array<int, array<int, int>> */
    private function byVariantAndLocation(Shop $shop, string $column): array
    {
        $out = [];
        DB::table('inventory_levels')
            ->join('locations', 'locations.id', '=', 'inventory_levels.location_id')
            ->where('inventory_levels.shop_id', $shop->id)->where('locations.is_active', true)
            ->orderBy('inventory_levels.id')
            ->each(function ($r) use (&$out, $column) {
                $out[(int) $r->variant_id][(int) $r->location_id] = (int) $r->{$column};
            }, 5000);

        return $out;
    }

    public function activeVariantInfo(Shop $shop): array
    {
        return DB::table('variants')->where('shop_id', $shop->id)->where('is_active', true)
            ->get(['id', 'tracked', 'shopify_created_at'])
            ->mapWithKeys(fn ($v) => [(int) $v->id => ['tracked' => (bool) $v->tracked, 'created' => $v->shopify_created_at]])
            ->all();
    }

    public function countTrackedVariants(Shop $shop): int
    {
        return DB::table('variants')->where('shop_id', $shop->id)->where('is_active', true)->where('tracked', true)->count();
    }

    public function facets(Shop $shop): array
    {
        $values = fn (string $column) => DB::table('variants')->where('shop_id', $shop->id)->where('is_active', true)->where('tracked', true)
            ->whereNotNull($column)->distinct()->orderBy($column)->pluck($column)->all();

        return ['vendors' => $values('vendor'), 'product_types' => $values('product_type')];
    }

    public function vendorSummary(Shop $shop): array
    {
        return DB::table('variants')->where('shop_id', $shop->id)->where('is_active', true)->where('tracked', true)
            ->whereNotNull('vendor')
            ->groupBy('vendor')->orderByRaw('COUNT(*) DESC')->orderBy('vendor')
            ->selectRaw('vendor, COUNT(*) as products, SUM(CASE WHEN supplier_id IS NULL THEN 0 ELSE 1 END) as with_supplier')
            ->get()->map(fn ($r) => ['vendor' => $r->vendor, 'products' => (int) $r->products, 'with_supplier' => (int) $r->with_supplier])->all();
    }

    public function variantsOfVendor(Shop $shop, string $vendor): array
    {
        return DB::table('variants')->where('shop_id', $shop->id)->where('is_active', true)->where('tracked', true)
            ->where('vendor', $vendor)->get(['id', 'supplier_id'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'supplier_id' => $r->supplier_id !== null ? (int) $r->supplier_id : null])->all();
    }
}
