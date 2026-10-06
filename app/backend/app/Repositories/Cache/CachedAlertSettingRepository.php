<?php

namespace App\Repositories\Cache;

use App\Models\AlertSetting;
use App\Models\Shop;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;

/** AlertSettingObserver invalidates on save. */
class CachedAlertSettingRepository implements AlertSettingRepositoryInterface
{
    public function __construct(
        private readonly AlertSettingRepositoryInterface $inner,
        private readonly Cache $cache,
    ) {}

    public function forShop(Shop $shop): ?AlertSetting
    {
        $key = CacheKeys::alertSetting($shop->id);
        $cached = $this->cache->get($key);
        if ($cached instanceof AlertSetting) {
            return $cached;
        }

        $setting = $this->inner->forShop($shop);
        if ($setting !== null) {
            $this->cache->put($key, $setting, CacheKeys::TTL_ALERT_SETTING);
        }

        return $setting;
    }

    public function upsert(Shop $shop, array $attributes): AlertSetting
    {
        return $this->inner->upsert($shop, $attributes);
    }
}
