<?php

namespace App\Services\App;

use App\Enums\AlertFrequency;
use App\Enums\Feature;
use App\Enums\RealtimeAlertMode;
use App\Enums\SyncType;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Jobs\SyncRealtimeWebhook;
use App\Models\Location;
use App\Models\Shop;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\FlowRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Sync\SyncService;
use App\Support\CacheVersion;
use App\Support\Entitlements;
use Illuminate\Validation\ValidationException;

/** Store-wide defaults and alert preferences. */
class SettingsService
{
    public function __construct(
        private readonly ShopRepositoryInterface $shops,
        private readonly AlertSettingRepositoryInterface $alerts,
        private readonly FlowRepositoryInterface $flow,
        private readonly SyncService $sync,
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
            'excluded_order_tags' => $shop->excluded_order_tags ?? [],
            'excluded_order_sources' => $shop->excluded_order_sources ?? [],
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
                // Every plan; null when switched off app-wide.
                'weekly_summary' => Entitlements::for($shop)->has(Feature::WeeklySummary) ? ($alert?->weekly_summary ?? false) : null,
                'slack_webhook_url' => $alert?->slackUrl(),
                'cover_days' => $alert?->cover_days,
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

        // Excluded orders change the sales history itself: the whole window is read again from Shopify.
        $exclusions = [];
        foreach (['excluded_order_tags', 'excluded_order_sources'] as $key) {
            if (array_key_exists($key, $data)) {
                $values = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $data[$key] ?? []), fn ($v) => $v !== '')));
                sort($values);
                if ($values !== ($shop->{$key} ?? [])) {
                    $exclusions[$key] = $values === [] ? null : $values;
                }
            }
        }
        if ($exclusions !== []) {
            $shop = $this->shops->update($shop, $exclusions);
            $this->sync->start($shop, SyncType::Manual, full: true);
        }

        if (array_key_exists('locale', $data)) {
            $shop = $this->shops->update($shop, ['locale' => $data['locale']]);
        }

        if (isset($data['alerts'])) {
            $before = $this->realtimeWanted($shop);
            $this->alerts->upsert($shop, array_intersect_key($data['alerts'], array_flip(['email', 'enabled', 'frequency', 'weekly_day', 'realtime', 'slack_webhook_url', 'cover_days']))
                + (isset($data['alerts']['weekly_summary']) ? ['weekly_summary' => (bool) $data['alerts']['weekly_summary']] : []));
            if ($this->realtimeWanted($shop) !== $before) {
                SyncRealtimeWebhook::dispatch($shop->id);
            }
        }

        return $this->get($shop);
    }

    /** @return array<int, array{id: int, name: string, excluded: bool}> active locations */
    public function locations(Shop $shop): array
    {
        return Location::query()->forShop($shop)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'excluded'])
            ->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'excluded' => $l->excluded])->all();
    }

    /**
     * Stock at these locations is no longer counted (the others are counted again). At least
     * one location must stay: with none there would be no stock to forecast from.
     *
     * @param  array<int, int>  $excludedIds
     */
    public function excludeLocations(Shop $shop, array $excludedIds): array
    {
        $active = Location::query()->forShop($shop)->where('is_active', true)->pluck('id')->all();
        $excluded = array_values(array_intersect($active, array_map('intval', $excludedIds)));
        if ($active !== [] && count($excluded) >= count($active)) {
            throw ValidationException::withMessages(['excluded_ids' => 'keep_one_location']);
        }

        $changed = Location::query()->forShop($shop)->whereIn('id', $excluded)->where('excluded', false)->update(['excluded' => true])
            + Location::query()->forShop($shop)->whereNotIn('id', $excluded)->where('excluded', true)->update(['excluded' => false]);
        if ($changed > 0) {
            CacheVersion::bumpCatalog($shop->id);
            RecomputeForecasts::dispatch($shop->id);
        }

        return $this->locations($shop);
    }

    private function realtimeWanted(Shop $shop): bool
    {
        $alert = $this->alerts->forShop($shop);

        return Entitlements::for($shop)->has(Feature::RealtimeAlerts)
            && $alert !== null && $alert->enabled && (bool) $alert->email && ($alert->realtime ?? RealtimeAlertMode::Off) !== RealtimeAlertMode::Off;
    }
}
