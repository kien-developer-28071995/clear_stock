<?php

namespace App\Services\App;

use App\Enums\AlertFrequency;
use App\Enums\Feature;
use App\Enums\RealtimeAlertMode;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Jobs\SyncRealtimeWebhook;
use App\Models\Shop;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\FlowRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Support\Entitlements;

/** Store-wide defaults and alert preferences. */
class SettingsService
{
    public function __construct(
        private readonly ShopRepositoryInterface $shops,
        private readonly AlertSettingRepositoryInterface $alerts,
        private readonly FlowRepositoryInterface $flow,
    ) {}

    public function get(Shop $shop): array
    {
        $alert = $this->alerts->forShop($shop);

        return [
            'default_lead_time_days' => $shop->default_lead_time_days,
            'default_safety_days' => $shop->default_safety_days,
            // One-off sales spikes capped before averaging (hidden when switched off app-wide).
            'filter_sales_spikes' => Entitlements::for($shop)->has(Feature::SpikeFilter) ? $shop->filter_sales_spikes : null,
            'forecast_profile' => $shop->forecast_profile,
            'locale' => $shop->locale,
            'alerts' => [
                'available' => Entitlements::for($shop)->has(Feature::Alerts),
                'email' => $alert?->email,
                'enabled' => $alert?->enabled ?? false,
                'frequency' => ($alert?->frequency ?? AlertFrequency::Daily)->value,
                'weekly_day' => $alert?->weekly_day ?? 1,
                // Growth only; the inventory webhook exists only while this is on.
                'realtime_available' => Entitlements::for($shop)->has(Feature::RealtimeAlerts),
                'realtime' => ($alert?->realtime ?? RealtimeAlertMode::Off)->value,
            ],
            // Shopify Flow triggers (Growth): set up in the Flow app; `active` = a workflow uses one.
            'flow' => [
                'available' => Entitlements::for($shop)->has(Feature::FlowTriggers),
                'active' => $this->flow->hasEnabledFlow($shop),
            ],
        ];
    }

    public function update(Shop $shop, array $data): array
    {
        $defaults = array_filter(
            array_intersect_key($data, array_flip(['default_lead_time_days', 'default_safety_days', 'filter_sales_spikes', 'forecast_profile'])),
            fn ($v) => $v !== null,
        );
        if ($defaults !== []) {
            $changed = $defaults != array_intersect_key($shop->only(array_keys($defaults)), $defaults);
            $shop = $this->shops->update($shop, $defaults);
            if ($changed) {
                RecomputeForecasts::dispatch($shop->id);
            }
        }

        if (array_key_exists('locale', $data)) {
            $shop = $this->shops->update($shop, ['locale' => $data['locale']]);
        }

        if (isset($data['alerts'])) {
            $before = $this->realtimeWanted($shop);
            $this->alerts->upsert($shop, array_intersect_key($data['alerts'], array_flip(['email', 'enabled', 'frequency', 'weekly_day', 'realtime'])));
            if ($this->realtimeWanted($shop) !== $before) {
                SyncRealtimeWebhook::dispatch($shop->id);
            }
        }

        return $this->get($shop);
    }

    private function realtimeWanted(Shop $shop): bool
    {
        $alert = $this->alerts->forShop($shop);

        return Entitlements::for($shop)->has(Feature::RealtimeAlerts)
            && $alert !== null && $alert->enabled && (bool) $alert->email && ($alert->realtime ?? RealtimeAlertMode::Off) !== RealtimeAlertMode::Off;
    }
}
