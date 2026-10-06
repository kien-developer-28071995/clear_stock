<?php

namespace App\Repositories\Eloquent;

use App\Models\BundleComponent;
use App\Models\ForecastOverride;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\ForecastRepositoryInterface;
use App\Support\CacheKeys;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EloquentForecastRepository implements ForecastRepositoryInterface
{
    private const CHUNK = 500;

    public function forecastableVariantIds(Shop $shop): array
    {
        return DB::table('variants')->where('shop_id', $shop->id)
            ->where('is_active', true)->where('tracked', true)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function discontinuedVariantIds(Shop $shop): array
    {
        return DB::table('variants')->where('shop_id', $shop->id)->where('discontinued', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function variantsWithSuppliers(Shop $shop, array $ids): Collection
    {
        return Variant::query()->forShop($shop)->whereIn('id', $ids)->with('supplier')->get()->keyBy('id');
    }

    public function manualBundlesContaining(Shop $shop, array $componentIds): array
    {
        $out = [];
        BundleComponent::query()->forShop($shop)
            ->where('source', BundleComponent::SOURCE_MANUAL)
            ->whereIn('component_variant_id', $componentIds)
            ->with('bundle:id,product_title,title,is_active')
            ->get()
            ->each(function (BundleComponent $c) use (&$out) {
                if ($c->bundle?->is_active) {
                    $out[$c->component_variant_id][$c->bundle_variant_id] = ['quantity' => $c->quantity, 'name' => $c->bundle->displayName()];
                }
            });

        return $out;
    }

    public function activeOverrides(Shop $shop, array $variantIds): array
    {
        $out = [];
        ForecastOverride::query()->forShop($shop)->active()->whereIn('variant_id', $variantIds)->get()
            ->each(function (ForecastOverride $o) use (&$out) {
                $out[$o->variant_id][$o->field->value] = [
                    'value' => (float) $o->value,
                    'note' => $o->note,
                    'expires_at' => $o->expires_at?->toDateString(),
                ];
            });

        return $out;
    }

    public function replaceForecasts(Shop $shop, array $rows): void
    {
        $now = now();
        $rows = array_map(fn ($r) => $r + ['shop_id' => $shop->id, 'location_id' => null, 'created_at' => $now, 'updated_at' => $now], $rows);

        // location_id is NULL for combined forecasts, which a unique index cannot de-duplicate:
        // delete + insert inside one transaction instead of upsert.
        DB::transaction(function () use ($shop, $rows) {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('forecasts')->where('shop_id', $shop->id)->whereNull('location_id')
                    ->whereIn('variant_id', array_column($chunk, 'variant_id'))->delete();
                DB::table('forecasts')->insert($chunk);
            }
        });
        $this->bumpVersion($shop);
    }

    public function replaceLocationForecasts(Shop $shop, array $variantIds, array $rows): void
    {
        $now = now();
        $rows = array_map(fn ($r) => $r + ['shop_id' => $shop->id, 'created_at' => $now, 'updated_at' => $now], $rows);

        DB::transaction(function () use ($shop, $variantIds, $rows) {
            foreach (array_chunk($variantIds, self::CHUNK) as $ids) {
                DB::table('forecasts')->where('shop_id', $shop->id)->whereNotNull('location_id')->whereIn('variant_id', $ids)->delete();
            }
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('forecasts')->insert($chunk);
            }
        });
        $this->bumpVersion($shop);
    }

    public function deleteLocationForecasts(Shop $shop): int
    {
        $deleted = DB::table('forecasts')->where('shop_id', $shop->id)->whereNotNull('location_id')->delete();
        if ($deleted > 0) {
            $this->bumpVersion($shop);
        }

        return $deleted;
    }

    public function setOverride(Shop $shop, int $variantId, string $field, float $value, ?string $note, ?string $expiresAt): void
    {
        ForecastOverride::query()->withoutGlobalScope('shop')->updateOrCreate(
            ['variant_id' => $variantId, 'field' => $field],
            ['shop_id' => $shop->id, 'value' => $value, 'note' => $note, 'expires_at' => $expiresAt],
        );
    }

    public function removeOverride(Shop $shop, int $variantId, string $field): void
    {
        ForecastOverride::query()->forShop($shop)->where('variant_id', $variantId)->where('field', $field)->delete();
    }

    public function deleteForecastsExcept(Shop $shop, array $keepVariantIds): int
    {
        $keep = array_flip($keepVariantIds);
        $stale = DB::table('forecasts')->where('shop_id', $shop->id)->pluck('variant_id')
            ->reject(fn ($id) => isset($keep[$id]))->unique()->values()->all();

        $deleted = 0;
        foreach (array_chunk($stale, self::CHUNK) as $ids) {
            $deleted += DB::table('forecasts')->where('shop_id', $shop->id)->whereIn('variant_id', $ids)->delete();
        }

        if ($deleted > 0) {
            $this->bumpVersion($shop);
        }

        return $deleted;
    }

    public function referenceRates(Shop $shop, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }
        $avgs = DB::table('forecasts')->where('shop_id', $shop->id)->whereNull('location_id')->whereIn('variant_id', $variantIds)
            ->pluck('avg_daily_sales', 'variant_id')->all();

        return Variant::query()->forShop($shop)->whereIn('id', $variantIds)->get()
            ->mapWithKeys(fn (Variant $v) => [$v->id => ['name' => $v->displayName(), 'avg' => isset($avgs[$v->id]) ? (float) $avgs[$v->id] : null]])
            ->all();
    }

    public function revenueSince(Shop $shop, array $variantIds, string $fromDate): array
    {
        $out = [];
        foreach (array_chunk($variantIds, self::CHUNK) as $ids) {
            $prices = DB::table('variants')->where('shop_id', $shop->id)->whereIn('id', $ids)->whereNotNull('price')
                ->pluck('price', 'id')->all();
            $units = DB::table('daily_sales')->where('shop_id', $shop->id)->whereIn('variant_id', array_keys($prices))
                ->where('date', '>=', $fromDate)->groupBy('variant_id')
                ->selectRaw('variant_id, SUM(units_sold) - SUM(units_returned) as net')
                ->pluck('net', 'variant_id')->all();

            foreach ($prices as $id => $price) {
                $out[(int) $id] = max(0, (int) ($units[$id] ?? 0)) * (float) $price;
            }
        }

        return $out;
    }

    public function saveAbcClasses(Shop $shop, array $classes): void
    {
        DB::transaction(function () use ($shop, $classes) {
            DB::table('variants')->where('shop_id', $shop->id)
                ->where(fn ($q) => $q->whereNotNull('abc_class')->orWhere('revenue_90d', '>', 0))
                ->update(['abc_class' => null, 'revenue_90d' => 0, 'revenue_share' => 0]);

            // One UPDATE per chunk with CASE per column (values differ per row).
            foreach (array_chunk($classes, self::CHUNK, true) as $chunk) {
                $cases = ['abc_class' => [], 'revenue_90d' => [], 'revenue_share' => []];
                $bindings = ['abc_class' => [], 'revenue_90d' => [], 'revenue_share' => []];
                foreach ($chunk as $id => $c) {
                    foreach (['abc_class' => $c['class'], 'revenue_90d' => $c['revenue'], 'revenue_share' => $c['share']] as $col => $value) {
                        $cases[$col][] = 'WHEN ? THEN ?';
                        array_push($bindings[$col], (int) $id, $value);
                    }
                }
                $set = [];
                $params = [];
                foreach ($cases as $col => $whens) {
                    $set[] = "{$col} = CASE id ".implode(' ', $whens).' END';
                    array_push($params, ...$bindings[$col]);
                }
                $ids = array_map('intval', array_keys($chunk));
                DB::update(
                    'UPDATE variants SET '.implode(', ', $set).' WHERE shop_id = ? AND id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                    [...$params, $shop->id, ...$ids],
                );
            }
        });
    }

    private function bumpVersion(Shop $shop): void
    {
        $key = CacheKeys::forecastVersion($shop->id);
        Cache::add($key, 0, now()->addDays(30));
        Cache::increment($key);
    }

    public function locationMinimums(Shop $shop, array $variantIds): array
    {
        $out = [];
        DB::table('location_minimums')->where('shop_id', $shop->id)->whereIn('variant_id', $variantIds)->get(['variant_id', 'location_id', 'min_stock'])
            ->each(function ($r) use (&$out) {
                $out[(int) $r->variant_id][(int) $r->location_id] = (int) $r->min_stock;
            });

        return $out;
    }

    public function setLocationMinimums(Shop $shop, int $variantId, array $minimums): void
    {
        $now = now();
        foreach ($minimums as $locationId => $min) {
            if ($min === null) {
                DB::table('location_minimums')->where('shop_id', $shop->id)->where('variant_id', $variantId)->where('location_id', $locationId)->delete();
            } else {
                DB::table('location_minimums')->upsert(
                    [['shop_id' => $shop->id, 'variant_id' => $variantId, 'location_id' => $locationId, 'min_stock' => $min, 'created_at' => $now, 'updated_at' => $now]],
                    ['variant_id', 'location_id'], ['min_stock', 'updated_at'],
                );
            }
        }
    }

    public function saveWeeklySnapshots(Shop $shop, string $weekStart, array $rows): void
    {
        $now = now();
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('forecast_snapshots')->insertOrIgnore(array_map(fn ($r) => [
                'shop_id' => $shop->id,
                'variant_id' => $r['variant_id'],
                'week_start' => $weekStart,
                'avg_daily_sales' => $r['avg_daily_sales'],
                'avg_source' => $r['avg_source'],
                'has_bundles' => $r['has_bundles'],
                'created_at' => $now,
            ], $chunk));
        }
    }

    public function pruneSnapshots(Shop $shop, string $before): int
    {
        return DB::table('forecast_snapshots')->where('shop_id', $shop->id)->where('week_start', '<', $before)->delete();
    }

    public function bundlesWithComponents(Shop $shop, array $componentIds): array
    {
        $bundleIds = DB::table('bundle_components')->where('shop_id', $shop->id)->where('source', BundleComponent::SOURCE_MANUAL)
            ->whereIn('component_variant_id', $componentIds)->distinct()->pluck('bundle_variant_id')->all();
        $rows = DB::table('bundle_components')->join('variants as b', 'b.id', '=', 'bundle_components.bundle_variant_id')
            ->join('variants as c', 'c.id', '=', 'bundle_components.component_variant_id')
            ->where('bundle_components.shop_id', $shop->id)->where('bundle_components.source', BundleComponent::SOURCE_MANUAL)
            ->whereIn('bundle_components.bundle_variant_id', $bundleIds)->where('b.is_active', true)
            ->get(['bundle_components.bundle_variant_id', 'bundle_components.component_variant_id', 'bundle_components.quantity', 'c.price']);

        $bundles = [];
        $prices = [];
        foreach ($rows as $r) {
            $bundles[(int) $r->bundle_variant_id][(int) $r->component_variant_id] = (int) $r->quantity;
            $prices[(int) $r->component_variant_id] = $r->price !== null ? (float) $r->price : null;
        }

        return ['bundles' => $bundles, 'prices' => $prices];
    }
}
