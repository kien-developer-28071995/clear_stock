<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\Forecast\ExplanationFormatter;
use App\Support\Entitlements;
use App\Support\ForecastStatusResolver;
use App\Support\Gid;
use Carbon\CarbonImmutable;

/**
 * Data for the Shopify admin extensions: the forecast block on the product page and
 * the bulk "set supplier and lead time" action on the product list.
 */
class ProductExtensionService
{
    public function __construct(
        private readonly VariantRepositoryInterface $variants,
        private readonly ExplanationFormatter $explanations,
        private readonly ForecastAdjustmentService $adjust,
    ) {}

    /**
     * Each variant of a Shopify product with its forecast, or why there is none
     * (`not_tracked`, `plan_limit`: beyond the plan's best sellers, `no_forecast`: not computed yet).
     */
    public function productForecast(Shop $shop, int $shopifyProductId): array
    {
        $today = CarbonImmutable::now($shop->timezone)->toDateString();
        $entitlements = Entitlements::for($shop);
        $explain = $entitlements->has(Feature::Explanations);

        $variants = $this->variants->findByShopifyProductIds($shop, [$shopifyProductId])->map(function (Variant $v) use ($today, $entitlements, $explain) {
            $f = $v->forecast;

            return [
                'variant_id' => $v->id,
                // Single-variant products are called "Default Title" in Shopify.
                'title' => $v->title !== null && $v->title !== 'Default Title' ? $v->title : null,
                'sku' => $v->sku,
                'forecast' => $f === null ? null : [
                    'status' => ForecastStatusResolver::for($f, $today)->value,
                    'current_stock' => $f->current_stock,
                    'incoming_stock' => $f->incoming_stock,
                    'avg_daily_sales' => (float) $f->avg_daily_sales,
                    'days_of_cover' => $f->days_of_cover !== null ? (float) $f->days_of_cover : null,
                    'stockout_date' => $f->stockout_date?->toDateString(),
                    'reorder_date' => $f->reorder_date?->toDateString(),
                    'suggested_qty' => $f->suggested_qty,
                    'confidence' => $f->confidence->value,
                    'explanation_lines' => $explain ? $this->explanations->lines($f->explanation ?? []) : [],
                ],
                'not_forecast_reason' => match (true) {
                    $f !== null => null,
                    ! $v->tracked => 'not_tracked',
                    $entitlements->maxSkus() !== null => 'plan_limit',
                    default => 'no_forecast',
                },
            ];
        })->values()->all();

        return ['synced' => $variants !== [], 'variants' => $variants];
    }

    /**
     * Applies the same settings (supplier, lead time, safety days) to every variant of
     * the selected Shopify products. Returns how many variants were updated.
     *
     * @param  array<int, string>  $productGids  gid://shopify/Product/…
     */
    public function applySettings(Shop $shop, array $productGids, array $settings): int
    {
        $ids = $this->variants->findByShopifyProductIds($shop, array_map(fn (string $gid) => Gid::id($gid), $productGids))->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }

        return $this->adjust->bulkUpdateVariantSettings($shop, $ids, $settings);
    }
}
