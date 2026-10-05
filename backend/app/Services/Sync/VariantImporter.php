<?php

namespace App\Services\Sync;

use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Support\Gid;
use App\Support\Payload;

/** Imports the variants bulk result (BulkQueries::variants) incl. native bundle components. */
class VariantImporter
{
    private const FLUSH_EVERY = 500;

    public function __construct(private readonly CatalogRepositoryInterface $catalog) {}

    /** @return array{variants: int, bundles: int} */
    public function import(Shop $shop, ?string $path): array
    {
        $rows = [];
        $count = 0;
        /** @var array<int, array<int, array{0: int, 1: int}>> bundle shopify id => [[component shopify id, qty]] */
        $components = [];
        $bundles = [];

        foreach ($path ? JsonlReader::read($path) : [] as $line) {
            if (isset($line['__parentId'])) {
                // ProductVariantComponent of a native bundle.
                $bundle = Gid::id($line['__parentId']);
                $component = Gid::id(Payload::get($line, 'productVariant', 'id'));
                if ($bundle && $component) {
                    $components[$bundle][] = [$component, Payload::int($line['quantity'] ?? null, 1, 100_000) ?? 1];
                }

                continue;
            }

            // A record we cannot place (no variant or product id) is left out, not guessed at.
            $variantId = Gid::id($line['id'] ?? null);
            $productId = Gid::id(Payload::get($line, 'product', 'id'));
            if ($variantId === null || $productId === null) {
                continue;
            }
            if (! empty($line['requiresComponents'])) {
                $bundles[$variantId] = true;
            }

            $rows[] = [
                'shopify_variant_id' => $variantId,
                'shopify_product_id' => $productId,
                'inventory_item_id' => Gid::id(Payload::get($line, 'inventoryItem', 'id')),
                'product_title' => Payload::text(Payload::get($line, 'product', 'title')) ?? '',
                'title' => Payload::text($line['title'] ?? null),
                'vendor' => Payload::text(Payload::get($line, 'product', 'vendor')),
                'product_type' => Payload::text(Payload::get($line, 'product', 'productType')),
                'sku' => Payload::text($line['sku'] ?? null),
                'barcode' => Payload::text($line['barcode'] ?? null),
                'shopify_unit_cost' => Payload::money(Payload::get($line, 'inventoryItem', 'unitCost', 'amount')),
                'price' => Payload::money($line['price'] ?? null),
                'tracked' => Payload::get($line, 'inventoryItem', 'tracked') === true,
                'is_active' => (Payload::get($line, 'product', 'status') ?? 'ACTIVE') === 'ACTIVE',
                'shopify_created_at' => Payload::utc($line['createdAt'] ?? null),
            ];
            $count++;

            if (count($rows) >= self::FLUSH_EVERY) {
                $this->catalog->upsertVariants($shop, $rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->catalog->upsertVariants($shop, $rows);
        }

        $this->importBundles($shop, $components, $bundles);

        return ['variants' => $count, 'bundles' => count($components)];
    }

    private function importBundles(Shop $shop, array $components, array $bundles): void
    {
        if ($components === [] && $bundles === []) {
            return;
        }

        $ids = $this->catalog->variantIdMap($shop);
        $bundleVariantIds = [];
        $rows = [];

        foreach (array_keys($components + $bundles) as $bundleShopifyId) {
            $bundleId = $ids[$bundleShopifyId] ?? null;
            if ($bundleId === null) {
                continue;
            }
            $bundleVariantIds[] = $bundleId;

            foreach ($components[$bundleShopifyId] ?? [] as [$componentShopifyId, $qty]) {
                if (isset($ids[$componentShopifyId])) {
                    $rows[] = ['bundle_variant_id' => $bundleId, 'component_variant_id' => $ids[$componentShopifyId], 'quantity' => $qty];
                }
            }
        }

        $this->catalog->replaceShopifyBundleComponents($shop, $bundleVariantIds, $rows);
    }
}
