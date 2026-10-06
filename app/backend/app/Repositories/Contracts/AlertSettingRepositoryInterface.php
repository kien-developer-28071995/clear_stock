<?php

namespace App\Repositories\Contracts;

use App\Models\AlertSetting;
use App\Models\Shop;

interface AlertSettingRepositoryInterface
{
    public function forShop(Shop $shop): ?AlertSetting;

    public function upsert(Shop $shop, array $attributes): AlertSetting;
}
