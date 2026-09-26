<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Support\Collection;

interface VariantRepositoryInterface
{
    public function find(Shop $shop, int $id): ?Variant;

    /** @return Collection<int, Variant> active variants matching title/SKU */
    public function search(Shop $shop, string $term, int $limit): Collection;

    /**
     * @param  array<int, int>  $shopifyVariantIds
     * @return Collection<int, Variant> keyed by shopify_variant_id
     */
    public function findByShopifyIds(Shop $shop, array $shopifyVariantIds): Collection;

    public function updateSettings(Variant $variant, array $settings): Variant;

    /** @param array<int, int> $ids @return int rows updated */
    public function bulkUpdateSettings(Shop $shop, array $ids, array $settings): int;

    /** Active variants with the fields used to match rows of an imported file (SKU, Shopify id, names). */
    public function forImport(Shop $shop): Collection;

    /** @return Collection<int, Variant> bundles with their components (+ component variant) */
    public function bundles(Shop $shop): Collection;

    /**
     * Replace the merchant-defined components of a bundle.
     *
     * @param  array<int, array{component_variant_id: int, quantity: int}>  $components
     */
    public function replaceManualComponents(Variant $bundle, array $components): void;
}
