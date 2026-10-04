<?php

namespace App\Repositories\Eloquent;

use App\Models\Forecast;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use App\Models\VariantAlertState;
use App\Repositories\Contracts\RealtimeAlertRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentRealtimeAlertRepository implements RealtimeAlertRepositoryInterface
{
    public function variantByInventoryItem(Shop $shop, int $inventoryItemId): ?Variant
    {
        return Variant::query()->forShop($shop)->where('inventory_item_id', $inventoryItemId)->where('is_active', true)->first();
    }

    public function locationByShopifyId(Shop $shop, int $shopifyLocationId): ?Location
    {
        return Location::query()->forShop($shop)->where('shopify_location_id', $shopifyLocationId)->first();
    }

    public function applyInventoryLevel(Shop $shop, Variant $variant, Location $location, int $available, CarbonInterface $updatedAt): bool
    {
        return DB::transaction(function () use ($shop, $variant, $location, $available, $updatedAt) {
            $level = InventoryLevel::query()->forShop($shop)
                ->where('variant_id', $variant->id)->where('location_id', $location->id)
                ->lockForUpdate()->first();

            if ($level?->shopify_updated_at !== null && $updatedAt->lt($level->shopify_updated_at)) {
                return false;
            }

            if ($level === null) {
                InventoryLevel::query()->create([
                    'shop_id' => $shop->id, 'variant_id' => $variant->id, 'location_id' => $location->id,
                    'available' => $available, 'incoming' => 0, 'shopify_updated_at' => $updatedAt,
                ]);
            } else {
                $level->update(['available' => $available, 'shopify_updated_at' => $updatedAt]);
            }

            return true;
        });
    }

    public function stockTotals(Variant $variant): array
    {
        $row = DB::table('inventory_levels')
            ->join('locations', 'locations.id', '=', 'inventory_levels.location_id')
            ->where('inventory_levels.variant_id', $variant->id)
            ->where('locations.is_active', true)->where('locations.excluded', false)
            ->selectRaw('COALESCE(SUM(inventory_levels.available), 0) as available, COALESCE(SUM(inventory_levels.incoming), 0) as incoming')
            ->first();

        return ['available' => (int) $row->available, 'incoming' => (int) $row->incoming];
    }

    public function stockByLocation(Shop $shop, array $variantIds): array
    {
        $out = [];
        DB::table('inventory_levels')
            ->join('locations', 'locations.id', '=', 'inventory_levels.location_id')
            ->where('inventory_levels.shop_id', $shop->id)
            ->whereIn('inventory_levels.variant_id', $variantIds)
            ->where('locations.is_active', true)->where('locations.excluded', false)
            ->orderBy('locations.name')
            ->get(['inventory_levels.variant_id', 'locations.name', 'inventory_levels.available'])
            ->each(function ($r) use (&$out) {
                $out[(int) $r->variant_id][] = ['name' => (string) $r->name, 'available' => (int) $r->available];
            });

        return $out;
    }

    public function totalForecast(Variant $variant): ?Forecast
    {
        return Forecast::query()->where('variant_id', $variant->id)->whereNull('location_id')->first();
    }

    public function totalForecasts(Shop $shop, array $variantIds): Collection
    {
        return Forecast::query()->forShop($shop)->whereNull('location_id')
            ->when($variantIds !== [], fn ($q) => $q->whereIn('variant_id', $variantIds))
            ->with('variant')->get()->keyBy('variant_id');
    }

    public function seedStates(Shop $shop, array $levels): void
    {
        $now = now();
        foreach (array_chunk($levels, 500, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $variantId => $level) {
                $rows[] = ['shop_id' => $shop->id, 'variant_id' => $variantId, 'level' => $level, 'pending_at' => null, 'created_at' => $now, 'updated_at' => $now];
            }
            VariantAlertState::query()->upsert($rows, ['variant_id'], ['level', 'pending_at', 'updated_at']);
        }
    }

    public function state(Variant $variant): VariantAlertState
    {
        return VariantAlertState::query()->where('variant_id', $variant->id)->first()
            ?? new VariantAlertState(['shop_id' => $variant->shop_id, 'variant_id' => $variant->id]);
    }

    public function saveState(VariantAlertState $state): void
    {
        $state->save();
    }

    public function pendingStates(Shop $shop): Collection
    {
        return VariantAlertState::query()->forShop($shop)->whereNotNull('pending_at')
            ->with('variant')->orderBy('pending_at')->get();
    }

    public function clearPending(array $stateIds, CarbonInterface $upTo): void
    {
        // A transition recorded after the batch was read stays pending for the next email.
        VariantAlertState::query()->whereIn('id', $stateIds)->where('pending_at', '<=', $upTo)->update(['pending_at' => null]);
    }

    public function shopsWithPending(): array
    {
        return VariantAlertState::query()->whereNotNull('pending_at')->distinct()->pluck('shop_id')->map(fn ($id) => (int) $id)->all();
    }

    public function shopsToReconcile(): array
    {
        return Shop::query()->whereNull('uninstalled_at')
            ->where(fn ($q) => $q->whereNotNull('realtime_webhook_id')
                ->orWhereExists(fn ($s) => $s->from('alert_settings')->whereColumn('alert_settings.shop_id', 'shops.id')->where('alert_settings.realtime', '!=', 'off')))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
