<?php

namespace App\Repositories\Eloquent;

use App\Models\BundleComponent;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\VariantRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentVariantRepository implements VariantRepositoryInterface
{
    public function find(Shop $shop, int $id): ?Variant
    {
        return Variant::query()->forShop($shop)->find($id);
    }

    public function search(Shop $shop, string $term, int $limit): Collection
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return Variant::query()->forShop($shop)->where('is_active', true)
            ->where(fn ($q) => $q->where('product_title', 'like', $like)->orWhere('title', 'like', $like)->orWhere('sku', 'like', $like))
            ->orderBy('product_title')->orderBy('title')->limit($limit)->get();
    }

    public function findByShopifyIds(Shop $shop, array $shopifyVariantIds): Collection
    {
        return Variant::query()->forShop($shop)->whereIn('shopify_variant_id', $shopifyVariantIds)->get()->keyBy('shopify_variant_id');
    }

    public function updateSettings(Variant $variant, array $settings): Variant
    {
        $variant->fill($settings)->save();

        return $variant;
    }

    public function bulkUpdateSettings(Shop $shop, array $ids, array $settings): int
    {
        return Variant::query()->forShop($shop)->whereIn('id', $ids)->update($settings);
    }

    public function bundles(Shop $shop): Collection
    {
        return Variant::query()->forShop($shop)->where('is_bundle', true)
            ->with('bundleComponents.component')->orderBy('product_title')->get();
    }

    public function replaceManualComponents(Variant $bundle, array $components): void
    {
        DB::transaction(function () use ($bundle, $components) {
            BundleComponent::query()->forShop($bundle->shop_id)->where('bundle_variant_id', $bundle->id)
                ->where('source', BundleComponent::SOURCE_MANUAL)->delete();

            foreach ($components as $c) {
                BundleComponent::query()->updateOrCreate(
                    ['bundle_variant_id' => $bundle->id, 'component_variant_id' => $c['component_variant_id']],
                    ['shop_id' => $bundle->shop_id, 'quantity' => $c['quantity'], 'source' => BundleComponent::SOURCE_MANUAL],
                );
            }

            $hasComponents = BundleComponent::query()->forShop($bundle->shop_id)->where('bundle_variant_id', $bundle->id)->exists();
            $bundle->forceFill(['is_bundle' => $hasComponents])->save();
        });
    }
}
