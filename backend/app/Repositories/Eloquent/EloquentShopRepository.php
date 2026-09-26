<?php

namespace App\Repositories\Eloquent;

use App\Models\Shop;
use App\Repositories\Contracts\ShopRepositoryInterface;
use Illuminate\Support\Facades\DB;

class EloquentShopRepository implements ShopRepositoryInterface
{
    /** Child tables, deleted leaf-first in batches so a big shop never locks a table for long. */
    private const SHOP_TABLES = [
        'alert_logs', 'alert_settings', 'variant_alert_states', 'inventory_transfers', 'forecasts', 'forecast_overrides', 'location_daily_sales', 'daily_sales',
        'inventory_levels', 'bundle_components', 'variants', 'locations', 'supplier_emails', 'suppliers', 'email_logs',
    ];

    private const PURGE_BATCH = 5000;

    public function findById(int $id): ?Shop
    {
        return Shop::query()->find($id);
    }

    public function findByDomain(string $domain): ?Shop
    {
        return Shop::query()->where('domain', strtolower($domain))->first();
    }

    public function updateOrCreateByDomain(string $domain, array $attributes): Shop
    {
        return Shop::query()->updateOrCreate(['domain' => strtolower($domain)], $attributes);
    }

    public function update(Shop $shop, array $attributes): Shop
    {
        $shop->fill($attributes)->save();

        return $shop;
    }

    public function purge(Shop $shop): void
    {
        foreach (self::SHOP_TABLES as $table) {
            do {
                $deleted = DB::table($table)->where('shop_id', $shop->id)->limit(self::PURGE_BATCH)->delete();
            } while ($deleted === self::PURGE_BATCH);
        }

        $shop->delete();
    }
}
