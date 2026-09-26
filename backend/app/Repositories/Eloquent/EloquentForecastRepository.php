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

    private function bumpVersion(Shop $shop): void
    {
        $key = CacheKeys::forecastVersion($shop->id);
        Cache::add($key, 0, now()->addDays(30));
        Cache::increment($key);
    }
}
