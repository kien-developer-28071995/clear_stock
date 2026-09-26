<?php

namespace App\Console\Commands;

use App\Jobs\SendAlertDigest;
use App\Models\AlertSetting;
use Illuminate\Console\Command;

/** Hourly: queue a digest check for every shop with alerts on (AlertService decides if it's due). */
class SendAlerts extends Command
{
    protected $signature = 'alerts:send';

    protected $description = 'Queue reorder digest emails that are due (shop time)';

    public function handle(): int
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

        $this->info("Checked {$queued} shop(s).");

        return self::SUCCESS;
    }
}
