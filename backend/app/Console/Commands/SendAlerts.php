<?php

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Jobs\SendAlertDigest;
use App\Jobs\SendRealtimeAlerts;
use App\Jobs\SendWeeklySummary;
use App\Support\Features;
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
        AlertSetting::query()->where('enabled', true)->where(fn ($q) => $q->whereNotNull('email')->orWhereNotNull('slack_webhook_url'))
            ->select(['id', 'shop_id'])
            ->chunkById(500, function ($settings) use (&$queued) {
                foreach ($settings as $setting) {
                    SendAlertDigest::dispatch($setting->shop_id);
                    $queued++;
                }
            });

        // Weekly summary emails (every plan, opt-in): the service decides if today is the day.
        $summaries = 0;
        if (Features::enabled(Feature::WeeklySummary)) {
            AlertSetting::query()->where('weekly_summary', true)->whereNotNull('email')
                ->select(['id', 'shop_id'])
                ->chunkById(500, function ($settings) use (&$summaries) {
                    foreach ($settings as $setting) {
                        SendWeeklySummary::dispatch($setting->shop_id);
                        $summaries++;
                    }
                });
        }

        $held = 0;
        foreach ($realtime->shopsWithPending() as $shopId) {
            SendRealtimeAlerts::dispatch($shopId);
            $held++;
        }

        $this->info("Checked {$queued} shop(s), {$summaries} weekly summaries, {$held} with real-time alerts waiting.");

        return self::SUCCESS;
    }
}
