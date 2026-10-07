<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Enums\OverrideField;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\ForecastRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\Forecast\ForecastService;
use App\Support\Entitlements;
use App\Support\Gid;
use Illuminate\Validation\ValidationException;

/**
 * Merchant changes to forecast inputs: overrides and per-SKU settings.
 * Single-variant edits are recomputed synchronously so the new numbers show at once.
 */
class ForecastAdjustmentService
{
    public function __construct(
        private readonly ForecastRepositoryInterface $forecasts,
        private readonly VariantRepositoryInterface $variants,
        private readonly ForecastService $engine,
        private readonly ChangeLogService $changes,
    ) {}

    /**
     * @param  array<string, array{value: ?float, note?: ?string, expires_at?: ?string}>  $overrides  field => value (null removes)
     */
    public function setOverrides(Shop $shop, Variant $variant, array $overrides): void
    {
        $before = $variant->overrides()->active()->get()->mapWithKeys(fn ($o) => ['override.'.$o->field->value => (float) $o->value])->all();
        $after = [];
        foreach (OverrideField::cases() as $field) {
            if (! array_key_exists($field->value, $overrides)) {
                continue;
            }
            $o = $overrides[$field->value];
            $after['override.'.$field->value] = $o === null || $o['value'] === null ? null : (float) $o['value'];
            if ($o === null || $o['value'] === null) {
                $this->forecasts->removeOverride($shop, $variant->id, $field->value);
            } else {
                $this->forecasts->setOverride($shop, $variant->id, $field->value, (float) $o['value'], $o['note'] ?? null, $o['expires_at'] ?? null);
            }
        }
        $this->changes->record($shop, $variant->id, $before, $after);

        $this->engine->runForShop($shop, [$variant->id]);
    }

    /** @param array{supplier_id?: ?int, lead_time_override?: ?int, safety_days?: ?int, min_order_qty?: ?int, pack_size?: ?int, min_stock?: ?int, max_stock?: ?int, alerts_muted?: bool, discontinued?: bool} $settings */
    public function updateVariantSettings(Shop $shop, Variant $variant, array $settings, array $reference = []): Variant
    {
        $this->assertMinBelowMax($settings + $variant->only(['min_stock', 'max_stock']));
        $settings += $this->referenceSettings($shop, $variant, $reference);
        $wasDiscontinued = $variant->discontinued;
        $before = $variant->only(array_keys($settings));
        $variant = $this->variants->updateSettings($variant, $settings);
        $this->changes->record($shop, $variant->id, $before, $settings);
        $this->engine->runForShop($shop, [$variant->id]);
        if ($variant->discontinued !== $wasDiscontinued) {
            $this->refreshLimitedPlan($shop);
        }

        return $variant;
    }

    /**
     * Manual reorder points per location (Growth). Only the shop's own active locations count.
     *
     * @param  array<int, array{location_id: int, min_stock: ?int}>  $minimums
     */
    public function setLocationMinimums(Shop $shop, Variant $variant, array $minimums): void
    {
        Entitlements::for($shop)->require(Feature::Locations);
        $own = Location::query()->forShop($shop)->where('is_active', true)->pluck('id')->all();
        $rows = [];
        foreach ($minimums as $m) {
            if (in_array((int) $m['location_id'], $own, true)) {
                $rows[(int) $m['location_id']] = $m['min_stock'] === null ? null : (int) $m['min_stock'];
            }
        }
        $this->forecasts->setLocationMinimums($shop, $variant->id, $rows);
        $this->engine->runForShop($shop, [$variant->id]);
    }

    /** @param array<int, int|string> $variantRefs local ids or Shopify variant gids */
    public function bulkUpdateVariantSettings(Shop $shop, array $variantRefs, array $settings): int
    {
        $gids = array_filter($variantRefs, 'is_string');
        $variantIds = array_values(array_unique(array_merge(
            array_filter($variantRefs, 'is_int'),
            $this->variants->findByShopifyIds($shop, array_map(fn ($g) => Gid::id($g), $gids))->pluck('id')->all(),
        )));

        $this->assertMinBelowMax($settings);
        $before = $settings === [] ? [] : $this->variants->findMany($shop, $variantIds)->mapWithKeys(fn (Variant $v) => [$v->id => $v->only(array_keys($settings))])->all();
        $updated = $this->variants->bulkUpdateSettings($shop, $variantIds, $settings);
        $this->changes->recordMany($shop, $before, $settings);
        RecomputeForecasts::dispatch($shop->id, $variantIds);
        if (array_key_exists('discontinued', $settings)) {
            $this->refreshLimitedPlan($shop);
        }

        return $updated;
    }

    /**
     * Settings of many products at once, each its own (a file import). Same bookkeeping as a
     * change made on the product page, with one recompute for all of them.
     *
     * @param  array<int, array<string, mixed>>  $settingsByVariant  variant id => settings to change
     */
    public function applyVariantSettings(Shop $shop, array $settingsByVariant): int
    {
        $groups = [];
        foreach ($settingsByVariant as $id => $settings) {
            ksort($settings);
            $groups[json_encode($settings)][] = (int) $id;
        }
        $updated = 0;
        $discontinued = false;
        foreach ($groups as $encoded => $ids) {
            $settings = json_decode($encoded, true);
            $before = $this->variants->findMany($shop, $ids)->mapWithKeys(fn (Variant $v) => [$v->id => $v->only(array_keys($settings))])->all();
            $updated += $this->variants->bulkUpdateSettings($shop, $ids, $settings);
            $this->changes->recordMany($shop, $before, $settings);
            $discontinued = $discontinued || array_key_exists('discontinued', $settings);
        }
        if ($settingsByVariant !== []) {
            RecomputeForecasts::dispatch($shop->id, array_map('intval', array_keys($settingsByVariant)));
        }
        if ($discontinued) {
            $this->refreshLimitedPlan($shop);
        }

        return $updated;
    }

    /**
     * Discontinued products sit outside the Free plan's product limit, so marking one frees a
     * slot (or unmarking takes one): the whole shop is recomputed to pick the right products.
     */
    private function refreshLimitedPlan(Shop $shop): void
    {
        if (Entitlements::for($shop)->maxSkus() !== null) {
            RecomputeForecasts::dispatch($shop->id);
        }
    }

    /**
     * A similar product for a new one (Starter+). Given as a local id or a Shopify gid (resource
     * picker); must be another product of the shop. Clearing it is always allowed.
     *
     * @param  array{reference_variant?: int|string|null, reference_percent?: ?int}  $data
     */
    private function referenceSettings(Shop $shop, Variant $variant, array $data): array
    {
        $out = array_key_exists('reference_percent', $data) ? ['reference_percent' => $data['reference_percent']] : [];
        if (! array_key_exists('reference_variant', $data)) {
            return $out;
        }
        $ref = $data['reference_variant'];
        if ($ref === null) {
            return $out + ['reference_variant_id' => null, 'reference_percent' => null];
        }
        Entitlements::for($shop)->require(Feature::ReferenceProducts);

        $target = is_int($ref) ? $this->variants->find($shop, $ref) : $this->variants->findByShopifyIds($shop, [Gid::id($ref)])->first();
        if ($target === null || $target->id === $variant->id) {
            throw ValidationException::withMessages(['reference_variant' => 'invalid_product']);
        }

        return $out + ['reference_variant_id' => $target->id];
    }

    /** A manual maximum below the minimum would never order enough. */
    private function assertMinBelowMax(array $settings): void
    {
        $min = $settings['min_stock'] ?? null;
        $max = $settings['max_stock'] ?? null;
        if ($min !== null && $max !== null && $max < $min) {
            throw ValidationException::withMessages(['max_stock' => 'max_below_min']);
        }
    }
}
