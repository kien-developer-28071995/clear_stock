<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Enums\Plan;
use App\Enums\RealtimeAlertMode;
use App\Models\BundleComponent;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\FlowRepositoryInterface;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Support\Entitlements;

/**
 * What a shop stops getting when it moves to a smaller plan, measured on its own
 * data ("120 products lose their forecast", "3 suppliers stop getting automatic
 * orders"), so the merchant confirms knowing the consequences. Returns codes with
 * params; the app writes the sentences. Nothing is deleted on downgrade.
 */
class DowngradeImpactService
{
    private const RANK = ['free' => 0, 'starter' => 1, 'growth' => 2];

    public function __construct(
        private readonly CatalogRepositoryInterface $catalog,
        private readonly VariantRepositoryInterface $variants,
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly AlertSettingRepositoryInterface $alerts,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly FlowRepositoryInterface $flow,
    ) {}

    public static function isDowngrade(Plan $from, Plan $to): bool
    {
        return self::RANK[$to->value] < self::RANK[$from->value];
    }

    /** @return array<int, array{code: string, params: array<string, mixed>}> empty when nothing is lost */
    public function impact(Shop $shop, Plan $target): array
    {
        $current = Entitlements::for($shop);
        $limits = config("billing.plans.{$target->value}.limits");
        $loses = fn (Feature $f) => $current->has($f) && empty($limits[$f->value]);
        $out = [];

        // Fewer products forecast.
        $max = $limits['max_skus'] ?? null;
        if ($max !== null && ($current->maxSkus() === null || $current->maxSkus() > $max)) {
            $tracked = $this->catalog->countTrackedVariants($shop);
            if ($tracked > $max) {
                $out[] = $this->line('forecast_limit', ['limit' => $max, 'count' => $tracked - $max]);
            }
        }

        if ($loses(Feature::Bundles)) {
            $manual = $this->variants->bundles($shop)->filter(
                fn (Variant $b) => $b->bundleComponents->contains(fn ($c) => $c->source === BundleComponent::SOURCE_MANUAL),
            )->count();
            $out[] = $this->line('bundles', ['count' => $manual]);
        }

        if ($loses(Feature::Alerts)) {
            $setting = $this->alerts->forShop($shop);
            $out[] = $this->line($setting?->enabled && $setting->email ? 'alerts_active' : 'alerts', array_filter(['email' => $setting?->enabled ? $setting->email : null]));
        }

        if ($loses(Feature::RealtimeAlerts)) {
            // The inventory webhook is removed right after the plan change (SyncRealtimeWebhook).
            $setting = $this->alerts->forShop($shop);
            $on = $setting?->enabled && $setting->email && ($setting->realtime ?? RealtimeAlertMode::Off) !== RealtimeAlertMode::Off;
            $out[] = $this->line($on ? 'realtime_alerts_active' : 'realtime_alerts', array_filter(['email' => $on ? $setting->email : null]));
        }

        if ($loses(Feature::Locations)) {
            $out[] = $this->line('locations', ['count' => count($this->forecasts->activeLocations($shop))]);
        }

        if ($loses(Feature::PurchaseOrders)) {
            $out[] = $this->line('purchase_orders');
        }

        if ($loses(Feature::SupplierEmails)) {
            $out[] = $this->line('supplier_emails');
        }

        if ($loses(Feature::SupplierAutoEmail)) {
            $auto = $this->suppliers->allForShop($shop)->filter(fn (Supplier $s) => $s->auto_email && $s->email)->count();
            if ($auto > 0) {
                $out[] = $this->line('supplier_auto_emails', ['count' => $auto]);
            }
        }

        if ($loses(Feature::FlowTriggers)) {
            $out[] = $this->line($this->flow->hasEnabledFlow($shop) ? 'flow_triggers_active' : 'flow_triggers');
        }

        if ($loses(Feature::ReferenceProducts)) {
            $count = Variant::query()->forShop($shop)->whereNotNull('reference_variant_id')->count();
            if ($count > 0) {
                $out[] = $this->line('reference_products', ['count' => $count]);
            }
        }

        if ($loses(Feature::WhatIf)) {
            $out[] = $this->line('what_if');
        }

        return $out;
    }

    /** @return array{code: string, params: array<string, mixed>} */
    private function line(string $code, array $params = []): array
    {
        return ['code' => $code, 'params' => $params];
    }
}
