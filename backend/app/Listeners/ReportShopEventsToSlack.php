<?php

namespace App\Listeners;

use App\Events\PlanChanged;
use App\Events\ShopInstalled;
use App\Events\ShopUninstalled;
use App\Jobs\PostShopEventToSlack;
use App\Models\Shop;
use App\Monitoring\ShopEventMessage;

/** Install, uninstall, upgrade and downgrade messages for the events Slack channel. */
class ReportShopEventsToSlack
{
    public function installed(ShopInstalled $event): void
    {
        $this->post(fn () => ShopEventMessage::installed($event->shop->fresh() ?? $event->shop, $event->reinstall, $this->activeInstalls()));
    }

    public function uninstalled(ShopUninstalled $event): void
    {
        $this->post(fn () => ShopEventMessage::uninstalled($event->shop, $event->plan, $this->activeInstalls()));
    }

    public function planChanged(PlanChanged $event): void
    {
        $this->post(fn () => ShopEventMessage::planChanged($event->shop, $event->from, $event->fromInterval, $event->to, $event->toInterval));
    }

    private function post(callable $message): void
    {
        if (config('monitoring.events_slack_webhook_url')) {
            PostShopEventToSlack::dispatch($message());
        }
    }

    private function activeInstalls(): int
    {
        return Shop::query()->whereNull('uninstalled_at')->whereNotNull('access_token')->count();
    }
}
