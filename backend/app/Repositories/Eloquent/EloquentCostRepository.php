<?php

namespace App\Repositories\Eloquent;

use App\Models\Shop;
use App\Repositories\Contracts\CostRepositoryInterface;
use App\Support\CacheKeys;
use App\Support\CacheVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentCostRepository implements CostRepositoryInterface
{
    public function counts(Shop $shop): array
    {
        $r = $this->base($shop)->selectRaw('COUNT(*) as n, SUM(CASE WHEN unit_cost IS NULL THEN 1 ELSE 0 END) as missing, '
            .'SUM(CASE WHEN cost_override IS NOT NULL THEN 1 ELSE 0 END) as overridden')->first();

        return ['tracked' => (int) $r->n, 'missing' => (int) $r->missing, 'overridden' => (int) $r->overridden];
    }

    public function list(Shop $shop, bool $missingOnly, ?string $search, int $limit): Collection
    {
        return $this->base($shop)
            ->when($missingOnly, fn ($q) => $q->whereNull('unit_cost'))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where(fn ($w) => $w->where('product_title', 'like', $like)->orWhere('title', 'like', $like)->orWhere('sku', 'like', $like));
            })
            // Best sellers first: their cost matters most.
            ->orderByDesc('revenue_90d')->orderBy('product_title')->orderBy('id')->limit($limit)
            ->get(['id', 'product_title', 'title', 'sku', 'barcode', 'shopify_unit_cost', 'cost_override', 'unit_cost']);
    }

    public function setOverrides(Shop $shop, array $costs): int
    {
        $updated = 0;
        DB::transaction(function () use ($shop, $costs, &$updated) {
            foreach ($costs as $id => $cost) {
                $updated += DB::table('variants')->where('shop_id', $shop->id)->where('id', $id)->update(['cost_override' => $cost]);
            }
            DB::table('variants')->where('shop_id', $shop->id)->whereIn('id', array_keys($costs))
                ->update(['unit_cost' => DB::raw('COALESCE(cost_override, shopify_unit_cost)')]);
        });
        // Money figures (stock value, plan spend) are cached per forecast and catalog version.
        CacheVersion::bump(CacheKeys::forecastVersion($shop->id));
        CacheVersion::bumpCatalog($shop->id);

        return $updated;
    }

    public function idsBySkuAndBarcode(Shop $shop): array
    {
        $out = [];
        foreach (DB::table('variants')->where('shop_id', $shop->id)->where('is_active', true)->orderBy('id')->get(['id', 'sku', 'barcode']) as $v) {
            foreach ([$v->sku, $v->barcode] as $key) {
                if ($key !== null && $key !== '') {
                    $out[mb_strtolower(trim($key))] ??= (int) $v->id;
                }
            }
        }

        return $out;
    }

    private function base(Shop $shop)
    {
        return DB::table('variants')->where('shop_id', $shop->id)->where('is_active', true)->where('tracked', true);
    }
}
