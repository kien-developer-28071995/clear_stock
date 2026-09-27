<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Models\ManualOrder;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\ManualOrderRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\Forecast\ExplanationFormatter;
use App\Support\Entitlements;
use App\Support\ForecastStatusResolver;
use App\Support\Gid;
use Carbon\CarbonImmutable;

/**
 * Data for the Shopify admin extensions: the forecast block on the product and variant
 * pages, "mark as ordered" and the reorder settings action (product page and product list).
 */
class ProductExtensionService
{
    public function __construct(
        private readonly VariantRepositoryInterface $variants,
        private readonly ExplanationFormatter $explanations,
        private readonly ForecastAdjustmentService $adjust,
        private readonly ManualOrderRepositoryInterface $manualOrders,
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

        $found = $this->variants->findByShopifyProductIds($shop, [$shopifyProductId]);
        // Orders placed outside Shopify ("mark as ordered") still counted as on the way.
        $ordered = $found->isEmpty() ? [] : $this->manualOrders->onTheWay($shop, ManualOrder::countedFrom($today), $found->pluck('id')->all());
        $variants = $found->map(function (Variant $v) use ($today, $entitlements, $explain, $ordered) {
            $f = $v->forecast;

            return [
                'variant_id' => $v->id,
                'shopify_variant_id' => $v->shopify_variant_id,
                'discontinued' => $v->discontinued,
                'ordered' => $ordered[$v->id] ?? null,
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

    /** The variant page: the same data as the product, for this one variant. */
    public function variantForecast(Shop $shop, int $shopifyVariantId): array
    {
        $variant = $this->variants->findByShopifyIds($shop, [$shopifyVariantId])->first();
        if ($variant === null) {
            return ['synced' => false, 'variants' => []];
        }
        $product = $this->productForecast($shop, $variant->shopify_product_id);

        return ['synced' => true, 'variants' => array_values(array_filter($product['variants'], fn ($v) => $v['variant_id'] === $variant->id))];
    }

    /**
     * Applies the same settings (supplier, lead time, safety days, minimum order, pack size,
     * discontinued) to every variant of
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
