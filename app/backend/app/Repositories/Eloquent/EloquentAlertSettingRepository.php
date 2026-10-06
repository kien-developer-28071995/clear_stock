<?php

namespace App\Repositories\Eloquent;

use App\Models\AlertSetting;
use App\Models\Shop;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;

class EloquentAlertSettingRepository implements AlertSettingRepositoryInterface
{
    public function forShop(Shop $shop): ?AlertSetting
    {
        return AlertSetting::query()->forShop($shop)->first();
    }

    public function upsert(Shop $shop, array $attributes): AlertSetting
    {
        return AlertSetting::query()->withoutGlobalScope('shop')->updateOrCreate(['shop_id' => $shop->id], $attributes);
    }
}
