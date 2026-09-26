<?php

namespace App\Services\App;

use App\Enums\AlertType;
use App\Enums\Feature;
use App\Enums\RealtimeAlertMode;
use App\Enums\StockLevel;
use App\Jobs\SendRealtimeAlerts;
use App\Mail\RealtimeStockAlertMail;
use App\Models\AlertSetting;
use App\Models\Forecast;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\AlertLogRepositoryInterface;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\RealtimeAlertRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Forecast\ExplanationFormatter;
use App\Services\Forecast\ForecastService;
use App\Services\Shopify\WebhookSubscriptionClient;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Real-time stock alerts (Growth). Live stock comes from a shop-specific
 * inventory_levels/update subscription that exists only while the merchant has them on.
 *
 * Not spamming is the point of the design:
 *  1. only a worsening transition (ok -> low -> out) of the whole variant counts, not every sale;
 *  2. a product is re-armed only once restocked clearly above its reorder point (hysteresis);
 *  3. a product reported in the last `realert_days` (digest or real-time) is not reported
 *     again, except once when it goes from "reorder soon" to "out of stock";
 *  4. transitions are batched into one email per `batch_minutes`;
 *  5. at most `max_per_day` emails per shop, and none during the shop's night.
 */
class RealtimeAlertService
{
    public const SENT = 'sent';

    private const TOPIC_GRAPHQL = 'INVENTORY_LEVELS_UPDATE';

    public function __construct(
        private readonly RealtimeAlertRepositoryInterface $repo,
        private readonly AlertSettingRepositoryInterface $settings,
        private readonly AlertLogRepositoryInterface $logs,
        private readonly ShopRepositoryInterface $shops,
        private readonly WebhookSubscriptionClient $webhooks,
        private readonly ForecastService $engine,
        private readonly ExplanationFormatter $formatter,
    ) {}

    /** Plan allows it and the merchant turned it on with an address to send to. */
    public function isActive(Shop $shop): bool
    {
        return $this->mode($shop, $this->settings->forShop($shop)) !== RealtimeAlertMode::Off;
    }

    /**
     * Create or remove the shop's inventory_levels/update subscription to match isActive().
     * Also replaces a subscription pointing at an old app URL. Safe to call repeatedly.
     */
    public function syncSubscription(Shop $shop): void
    {
        if (! $shop->isInstalled()) {
            if ($shop->realtime_webhook_id !== null) {
                $this->shops->update($shop, ['realtime_webhook_id' => null]); // Shopify dropped it on uninstall
            }

            return;
        }

        $wanted = $this->isActive($shop);
        $uri = rtrim((string) config('app.url'), '/').'/webhooks';
        $existing = $this->webhooks->list($shop, self::TOPIC_GRAPHQL);
        $keep = null;

        foreach ($existing as $subscription) {
            if ($wanted && $keep === null && $subscription['uri'] === $uri) {
                $keep = $subscription['id'];
            } else {
                $this->webhooks->delete($shop, $subscription['id']);
            }
        }

        if ($wanted && $keep === null) {
            $keep = $this->webhooks->create($shop, self::TOPIC_GRAPHQL, $uri);
            // Start from today's levels: products that were already low are not "news".
            $this->seedStates($shop);
            Log::info('Real-time alerts subscribed', ['shop' => $shop->domain]);
        } elseif (! $wanted && $existing !== []) {
            Log::info('Real-time alerts unsubscribed', ['shop' => $shop->domain]);
        }

        if ($shop->realtime_webhook_id !== $keep) {
            $this->shops->update($shop, ['realtime_webhook_id' => $keep]);
        }
    }

    /** @param array{inventory_item_id?: mixed, location_id?: mixed, available?: mixed, updated_at?: mixed} $payload */
    public function handleInventoryUpdate(Shop $shop, array $payload): void
    {
        if (! $this->isActive($shop) || ! isset($payload['inventory_item_id'], $payload['location_id'], $payload['available'])) {
            return;
        }

        $variant = $this->repo->variantByInventoryItem($shop, (int) $payload['inventory_item_id']);
        $location = $variant ? $this->repo->locationByShopifyId($shop, (int) $payload['location_id']) : null;
        if ($variant === null || $location === null) {
            return; // not synced yet: the next sync brings it in
        }

        $updatedAt = isset($payload['updated_at']) ? CarbonImmutable::parse((string) $payload['updated_at']) : CarbonImmutable::now();
        if (! $this->repo->applyInventoryLevel($shop, $variant, $location, (int) $payload['available'], $updatedAt)) {
            return; // an older update delivered late
        }

        if ($location->is_active) {
            $this->evaluate($shop, $variant);
        }
    }

    /** Compare live stock with the latest forecast and record a worsening transition. */
    public function evaluate(Shop $shop, Variant $variant): StockLevel
    {
        $forecast = $this->repo->totalForecast($variant);
        $totals = $this->repo->stockTotals($variant);
        $level = $forecast === null ? StockLevel::Ok : $this->classify($variant, $forecast, $totals['available'], $totals['incoming']);
        $state = $this->repo->state($variant);

        if ($level->severity() > $state->level->severity()) {
            $state->level = $level;
            // Refreshed on every worsening, so an escalation during a send is not cleared by it.
            $state->pending_at = now();
            $this->repo->saveState($state);
            SendRealtimeAlerts::dispatch($shop->id)->delay(now()->addMinutes((int) config('alerts.realtime.batch_minutes')));
        } elseif ($level->severity() < $state->level->severity()) {
            $position = $totals['available'] + $totals['incoming'];
            if ($level === StockLevel::Ok && $position >= $forecast->reorder_point + $this->rearmMargin($forecast->reorder_point)) {
                $state->level = StockLevel::Ok;
                $state->pending_at = null;
            } elseif ($state->level === StockLevel::Out && $totals['available'] > 0) {
                $state->level = StockLevel::Low; // back in stock but not yet safe: still armed only for "out"
            }
            if ($state->isDirty()) {
                $this->repo->saveState($state);
            }
        }

        return $state->level;
    }

    /** @return string self::SENT or the reason nothing was sent */
    public function flush(Shop $shop, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        $setting = $this->settings->forShop($shop);
        $mode = $this->mode($shop, $setting);
        $pending = $this->repo->pendingStates($shop);

        if ($pending->isEmpty()) {
            return 'nothing_pending';
        }
        if ($mode === RealtimeAlertMode::Off) {
            $this->repo->clearPending($pending->pluck('id')->all(), $now);

            return 'inactive';
        }

        $local = $now->setTimezone($shop->timezone);
        if ($local->hour < (int) config('alerts.realtime.send_from_hour') || $local->hour >= (int) config('alerts.realtime.send_until_hour')) {
            return 'quiet_hours'; // kept pending; the hourly alerts:send picks it up in the morning
        }
        if ($this->logs->countEmailsSince($shop, AlertType::Realtime, $local->startOfDay()) >= (int) config('alerts.realtime.max_per_day')) {
            return 'daily_cap';
        }

        $recent = $this->logs->recentVariantAlerts($shop, $now->subDays((int) config('alerts.realert_days')));
        $due = $pending->filter(function ($state) use ($mode, $recent) {
            $type = $state->level->alertType();
            if ($type === null || $state->variant === null || $state->variant->alerts_muted) {
                return false;
            }
            if ($mode === RealtimeAlertMode::OutOfStock && $type !== AlertType::OutOfStock) {
                return false;
            }
            $previous = $recent[$state->variant_id] ?? null;

            // Already reported lately; only "reorder soon" -> "out of stock" is news.
            return $previous === null || ($type === AlertType::OutOfStock && $previous === AlertType::ReorderNeeded->value);
        });

        $this->repo->clearPending($pending->pluck('id')->all(), $now);
        if ($due->isEmpty()) {
            return 'nothing_new';
        }

        // Fresh numbers for the email: the nightly forecast used yesterday's stock.
        $variantIds = $due->pluck('variant_id')->all();
        $this->engine->runForShop($shop, $variantIds, $now);
        $forecasts = $this->repo->totalForecasts($shop, $variantIds);
        $byLocation = $this->repo->stockByLocation($shop, $variantIds);

        $items = $due->sortByDesc(fn ($s) => $s->level->severity())->values()
            ->map(fn ($s) => $this->present($s->variant, $s->level, $forecasts[$s->variant_id] ?? null, $byLocation[$s->variant_id] ?? []))
            ->all();

        Mail::to($setting->email)->queue(new RealtimeStockAlertMail(shop: $shop, items: $items));

        $this->logs->logRealtime($shop, $due->map(fn ($s) => [
            'variant_id' => $s->variant_id,
            'type' => $s->level->alertType(),
            'stockout_date' => ($forecasts[$s->variant_id] ?? null)?->stockout_date?->toDateString(),
        ])->values()->all());

        Log::info('Real-time stock alert queued', ['shop' => $shop->domain, 'items' => count($items)]);

        return self::SENT;
    }

    private function mode(Shop $shop, ?AlertSetting $setting): RealtimeAlertMode
    {
        if (! $shop->isInstalled() || ! Entitlements::for($shop)->has(Feature::RealtimeAlerts)
            || $setting === null || ! $setting->enabled || ! $setting->email) {
            return RealtimeAlertMode::Off;
        }

        return $setting->realtime ?? RealtimeAlertMode::Off; // settings cached before the column existed
    }

    private function classify(Variant $variant, Forecast $forecast, int $available, int $incoming): StockLevel
    {
        // A product that doesn't sell (and has no manual minimum) never needs reordering.
        if ((float) $forecast->avg_daily_sales <= 0 && $variant->min_stock === null) {
            return StockLevel::Ok;
        }
        if ($available <= 0) {
            return StockLevel::Out;
        }

        return $available + $incoming <= $forecast->reorder_point ? StockLevel::Low : StockLevel::Ok;
    }

    private function rearmMargin(int $reorderPoint): int
    {
        return max((int) config('alerts.realtime.rearm_min_units'), (int) ceil($reorderPoint * (float) config('alerts.realtime.rearm_ratio')));
    }

    private function seedStates(Shop $shop): void
    {
        $levels = $this->repo->totalForecasts($shop, [])
            ->filter(fn (Forecast $f) => $f->variant !== null)
            ->map(fn (Forecast $f) => $this->classify($f->variant, $f, $f->current_stock, $f->incoming_stock)->value)
            ->all();
        $this->repo->seedStates($shop, $levels);
    }

    /** @param array<int, array{name: string, available: int}> $locations */
    private function present(Variant $variant, StockLevel $level, ?Forecast $forecast, array $locations): array
    {
        return [
            'variant_id' => $variant->id,
            'name' => $variant->displayName(),
            'sku' => $variant->sku,
            'out_of_stock' => $level === StockLevel::Out,
            'stock' => array_sum(array_column($locations, 'available')),
            // Only worth showing when stock is split across locations.
            'locations' => count($locations) > 1 ? $locations : [],
            'order_qty' => $forecast?->suggested_qty,
            'order_by' => $forecast?->reorder_date?->format('M j'),
            'why' => $forecast ? ($this->formatter->sentences($forecast->explanation, 'en')[0] ?? null) : null,
        ];
    }
}
