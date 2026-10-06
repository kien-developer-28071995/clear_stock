<?php

namespace App\Repositories\Eloquent;

use App\Models\InventoryTransfer;
use App\Models\Shop;
use App\Repositories\Contracts\TransferRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentTransferRepository implements TransferRepositoryInterface
{
    public function locationForecasts(Shop $shop): Collection
    {
        return DB::table('forecasts')
            ->join('variants', 'variants.id', '=', 'forecasts.variant_id')
            ->join('locations', 'locations.id', '=', 'forecasts.location_id')
            ->where('forecasts.shop_id', $shop->id)
            ->whereNotNull('forecasts.location_id')
            ->where('locations.is_active', true)->where('locations.excluded', false)
            ->where('variants.tracked', true)
            ->where('variants.is_active', true)
            ->whereNotNull('variants.inventory_item_id')
            ->get([
                'forecasts.variant_id', 'forecasts.location_id', 'forecasts.current_stock', 'forecasts.incoming_stock',
                'forecasts.avg_daily_sales', 'forecasts.reorder_point', 'forecasts.target_stock', 'forecasts.stockout_date',
                'forecasts.days_of_cover', 'variants.product_title', 'variants.title', 'variants.sku', 'variants.inventory_item_id',
            ])
            ->groupBy('variant_id');
    }

    public function createdSince(Shop $shop, \DateTimeInterface $since): Collection
    {
        return InventoryTransfer::query()->forShop($shop)->where('created_at', '>=', $since)
            ->with(['origin:id,name', 'destination:id,name'])->latest('created_at')->latest('id')->get();
    }

    public function create(Shop $shop, array $attributes): InventoryTransfer
    {
        return InventoryTransfer::query()->create($attributes + ['shop_id' => $shop->id]);
    }
}
