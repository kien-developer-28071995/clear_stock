<?php

namespace App\Console\Commands;

use App\Jobs\SendAlertDigest;
use App\Jobs\SendRealtimeAlerts;
use App\Models\AlertSetting;
use App\Repositories\Contracts\RealtimeAlertRepositoryInterface;
use Illuminate\Console\Command;

/**
 * Hourly: queue a digest check for every shop with alerts on (AlertService decides if it's due),
 * and flush real-time alerts held back by quiet hours or the daily cap.
 */
class SendAlerts extends Command
{
    protected $signature = 'alerts:send';

    protected $description = 'Queue reorder digest emails that are due (shop time)';

    public function handle(RealtimeAlertRepositoryInterface $realtime): int
    {
        $queued = 0;
        AlertSetting::query()->where('enabled', true)->whereNotNull('email')
            ->select(['id', 'shop_id'])
            ->chunkById(500, function ($settings) use (&$queued) {
                foreach ($settings as $setting) {
                    SendAlertDigest::dispatch($setting->shop_id);
                    $queued++;
                }
            });

        $held = 0;
        foreach ($realtime->shopsWithPending() as $shopId) {
            SendRealtimeAlerts::dispatch($shopId);
            $held++;
        }

        $this->info("Checked {$queued} shop(s), {$held} with real-time alerts waiting.");

        return self::SUCCESS;
    }
}
