<?php

namespace App\Services\Sync;

use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Support\Gid;
use Illuminate\Support\Carbon;

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
                $component = Gid::id($line['productVariant']['id'] ?? null);
                if ($bundle && $component) {
                    $components[$bundle][] = [$component, max(1, (int) $line['quantity'])];
                }

                continue;
            }

            $variantId = Gid::id($line['id']);
            if (! empty($line['requiresComponents'])) {
                $bundles[$variantId] = true;
            }

            $rows[] = [
                'shopify_variant_id' => $variantId,
                'shopify_product_id' => Gid::id($line['product']['id']),
                'inventory_item_id' => Gid::id($line['inventoryItem']['id'] ?? null),
                'product_title' => mb_substr((string) $line['product']['title'], 0, 255),
                'title' => $line['title'] !== null ? mb_substr((string) $line['title'], 0, 255) : null,
                'vendor' => trim((string) ($line['product']['vendor'] ?? '')) !== '' ? mb_substr(trim($line['product']['vendor']), 0, 255) : null,
                'product_type' => trim((string) ($line['product']['productType'] ?? '')) !== '' ? mb_substr(trim($line['product']['productType']), 0, 255) : null,
                'sku' => ($line['sku'] ?? '') !== '' ? mb_substr((string) $line['sku'], 0, 255) : null,
                'barcode' => trim((string) ($line['barcode'] ?? '')) !== '' ? mb_substr(trim($line['barcode']), 0, 255) : null,
                'unit_cost' => $line['inventoryItem']['unitCost']['amount'] ?? null,
                'tracked' => (bool) ($line['inventoryItem']['tracked'] ?? false),
                'is_active' => ($line['product']['status'] ?? 'ACTIVE') === 'ACTIVE',
                'shopify_created_at' => Carbon::parse($line['createdAt'])->utc()->toDateTimeString(),
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
