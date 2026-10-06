<?php

namespace App\Observers;

use App\Models\AlertSetting;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class AlertSettingObserver
{
    public function saved(AlertSetting $setting): void
    {
        Cache::forget(CacheKeys::alertSetting($setting->shop_id));
    }

    public function deleted(AlertSetting $setting): void
    {
        Cache::forget(CacheKeys::alertSetting($setting->shop_id));
    }
}
