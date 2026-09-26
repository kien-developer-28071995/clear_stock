<?php

namespace App\Services\App;

use App\Enums\OverrideField;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\ForecastRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\Forecast\ForecastService;
use App\Support\Gid;

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

    /** @param array{supplier_id?: ?int, lead_time_override?: ?int, safety_days?: ?int} $settings */
    public function updateVariantSettings(Shop $shop, Variant $variant, array $settings): Variant
    {
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

        $updated = $this->variants->bulkUpdateSettings($shop, $variantIds, $settings);
        RecomputeForecasts::dispatch($shop->id, $variantIds);

        return $updated;
    }
}
