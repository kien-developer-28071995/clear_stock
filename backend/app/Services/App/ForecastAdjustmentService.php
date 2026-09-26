<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Enums\OverrideField;
use App\Exceptions\PlanRequiredException;
use App\Jobs\Forecast\RecomputeForecasts;
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
    ) {}

    /**
     * @param  array<string, array{value: ?float, note?: ?string, expires_at?: ?string}>  $overrides  field => value (null removes)
     */
    public function setOverrides(Shop $shop, Variant $variant, array $overrides): void
    {
        foreach (OverrideField::cases() as $field) {
            if (! array_key_exists($field->value, $overrides)) {
                continue;
            }
            $o = $overrides[$field->value];
            if ($o === null || $o['value'] === null) {
                $this->forecasts->removeOverride($shop, $variant->id, $field->value);
            } else {
                $this->forecasts->setOverride($shop, $variant->id, $field->value, (float) $o['value'], $o['note'] ?? null, $o['expires_at'] ?? null);
            }
        }

        $this->engine->runForShop($shop, [$variant->id]);
    }

    /** @param array{supplier_id?: ?int, lead_time_override?: ?int, safety_days?: ?int, min_order_qty?: ?int, pack_size?: ?int, min_stock?: ?int, max_stock?: ?int, alerts_muted?: bool} $settings */
    public function updateVariantSettings(Shop $shop, Variant $variant, array $settings, array $reference = []): Variant
    {
        $this->assertMinBelowMax($settings + $variant->only(['min_stock', 'max_stock']));
        $settings += $this->referenceSettings($shop, $variant, $reference);
        $variant = $this->variants->updateSettings($variant, $settings);
        $this->engine->runForShop($shop, [$variant->id]);

        return $variant;
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
        $updated = $this->variants->bulkUpdateSettings($shop, $variantIds, $settings);
        RecomputeForecasts::dispatch($shop->id, $variantIds);

        return $updated;
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
        if (! Entitlements::for($shop)->has(Feature::ReferenceProducts)) {
            throw new PlanRequiredException(Feature::ReferenceProducts);
        }

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
