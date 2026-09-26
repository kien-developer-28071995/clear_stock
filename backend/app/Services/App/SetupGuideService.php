<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Enums\SetupStep;
use App\Models\Shop;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Home setup guide. Steps complete themselves from real data wherever possible
 * (sync finished, suppliers exist, alerts on); the rest from recorded events.
 */
class SetupGuideService
{
    /** Contextual tips the UI may show once each. */
    public const TIPS = ['home_actions', 'home_runway', 'product_explanation'];

    public const EVENTS = ['viewed_forecast'];

    public function __construct(
        private readonly ShopRepositoryInterface $shops,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly AlertSettingRepositoryInterface $alerts,
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly CatalogRepositoryInterface $catalog,
    ) {}

    public function state(Shop $shop): array
    {
        $guide = $shop->setup_guide ?? [];
        $skipped = $guide['skipped'] ?? [];
        $alert = $this->alerts->forShop($shop);
        $alertsAvailable = Entitlements::for($shop)->has(Feature::Alerts);

        $done = [
            SetupStep::ImportData->value => $shop->last_synced_at !== null,
            SetupStep::LeadTime->value => $shop->onboarded_at !== null,
            SetupStep::ReviewForecast->value => isset($guide['events']['viewed_forecast']),
            SetupStep::Suppliers->value => $this->suppliers->allForShop($shop)->isNotEmpty(),
            SetupStep::Alerts->value => $alertsAvailable && $alert?->enabled && $alert->email,
        ];

        $steps = array_map(fn (SetupStep $step) => [
            'key' => $step->value,
            'done' => (bool) $done[$step->value],
            'skipped' => ! $done[$step->value] && in_array($step->value, $skipped, true),
            'skippable' => $step->skippable(),
        ], SetupStep::cases());

        $completed = count(array_filter($steps, fn ($s) => $s['done'] || $s['skipped']));

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => count($steps),
            'dismissed' => isset($guide['dismissed_at']),
            'tips_dismissed' => array_values($guide['tips_dismissed'] ?? []),
            'context' => [
                'sync_running' => $shop->sync_status->value === 'running',
                'alerts_available' => $alertsAvailable,
                // The product to open for "see why a product needs reordering".
                'example_variant' => $this->exampleVariant($shop),
                // Suppliers can be created from Shopify vendors in one step.
                'vendor_count' => count($this->catalog->facets($shop)['vendors']),
            ],
        ];
    }

    public function recordEvent(Shop $shop, string $event): array
    {
        $this->assertIn($event, self::EVENTS, 'event');

        return $this->update($shop, function (array $g) use ($event) {
            $g['events'][$event] ??= now()->toIso8601String();

            return $g;
        });
    }

    public function skip(Shop $shop, SetupStep $step): array
    {
        if (! $step->skippable()) {
            throw ValidationException::withMessages(['step' => 'step_not_skippable']);
        }

        return $this->update($shop, function (array $g) use ($step) {
            $g['skipped'] = array_values(array_unique([...($g['skipped'] ?? []), $step->value]));

            return $g;
        });
    }

    public function setDismissed(Shop $shop, bool $dismissed): array
    {
        return $this->update($shop, function (array $g) use ($dismissed) {
            $g['dismissed_at'] = $dismissed ? now()->toIso8601String() : null;
            if (! $dismissed) {
                unset($g['dismissed_at']);
            }

            return $g;
        });
    }

    public function dismissTip(Shop $shop, string $tip): array
    {
        $this->assertIn($tip, self::TIPS, 'tip');

        return $this->update($shop, function (array $g) use ($tip) {
            $g['tips_dismissed'] = array_values(array_unique([...($g['tips_dismissed'] ?? []), $tip]));

            return $g;
        });
    }

    private function update(Shop $shop, callable $change): array
    {
        $shop = $this->shops->update($shop, ['setup_guide' => $change($shop->setup_guide ?? [])]);

        return $this->state($shop);
    }

    private function exampleVariant(Shop $shop): ?array
    {
        $today = CarbonImmutable::now($shop->timezone)->toDateString();
        $f = $this->forecasts->actionItems($shop, $today, 1)->first() ?? $this->forecasts->runway($shop, 1)->first();

        return $f ? ['id' => $f->variant_id, 'name' => $f->variant->displayName()] : null;
    }

    private function assertIn(string $value, array $allowed, string $field): void
    {
        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages([$field => 'unknown_value']);
        }
    }
}
