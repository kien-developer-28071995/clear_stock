<?php

namespace App\Services\App;

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Models\Supplier;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Most shops already fill the Vendor of their Shopify products: turn each vendor into
 * a supplier (or reuse the supplier with that name) and link its products, in one step.
 * Products that already have a supplier are kept unless the merchant asks to replace.
 */
class VendorSupplierService
{
    public function __construct(
        private readonly CatalogRepositoryInterface $catalog,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly VariantRepositoryInterface $variants,
    ) {}

    /** @return array{vendors: array<int, array{vendor: string, products: int, with_supplier: int, supplier: ?array{id: int, name: string}, is_store_name: bool}>} */
    public function preview(Shop $shop): array
    {
        $existing = $this->existingByName($shop);
        $store = mb_strtolower(trim((string) $shop->name));

        return ['vendors' => array_map(fn (array $v) => $v + [
            'supplier' => ($s = $existing[mb_strtolower($v['vendor'])] ?? null) ? ['id' => $s->id, 'name' => $s->name] : null,
            // Own-brand products usually carry the store name as vendor: not a supplier to order from.
            'is_store_name' => $store !== '' && mb_strtolower($v['vendor']) === $store,
        ], $this->catalog->vendorSummary($shop))];
    }

    /**
     * @param  array<int, string>  $vendors  vendors to turn into suppliers
     * @return array{suppliers_created: int, suppliers_reused: int, products_assigned: int, products_kept: int}
     */
    public function apply(Shop $shop, array $vendors, bool $replaceExisting): array
    {
        $known = array_column($this->catalog->vendorSummary($shop), 'vendor');
        $existing = $this->existingByName($shop);
        $result = ['suppliers_created' => 0, 'suppliers_reused' => 0, 'products_assigned' => 0, 'products_kept' => 0];

        DB::transaction(function () use ($shop, $vendors, $replaceExisting, $known, &$existing, &$result) {
            foreach (array_unique($vendors) as $vendor) {
                if (! in_array($vendor, $known, true)) {
                    continue; // unknown or no longer present
                }
                $key = mb_strtolower($vendor);
                if (isset($existing[$key])) {
                    $supplier = $existing[$key];
                    $result['suppliers_reused']++;
                } else {
                    $supplier = $existing[$key] = $this->suppliers->create($shop, ['name' => mb_substr($vendor, 0, 255)]);
                    $result['suppliers_created']++;
                }

                $assign = [];
                foreach ($this->catalog->variantsOfVendor($shop, $vendor) as $v) {
                    if ($v['supplier_id'] === null || ($replaceExisting && $v['supplier_id'] !== $supplier->id)) {
                        $assign[] = $v['id'];
                    } elseif ($v['supplier_id'] !== $supplier->id) {
                        $result['products_kept']++;
                    }
                }
                foreach (array_chunk($assign, 1000) as $chunk) {
                    $result['products_assigned'] += $this->variants->bulkUpdateSettings($shop, $chunk, ['supplier_id' => $supplier->id]);
                }
            }
        });

        if ($result['products_assigned'] > 0 || $result['suppliers_created'] > 0) {
            RecomputeForecasts::dispatch($shop->id); // supplier lead times apply
        }

        return $result;
    }

    /** @return array<string, Supplier> lower-cased name => supplier */
    private function existingByName(Shop $shop): array
    {
        return $this->suppliers->allForShop($shop)->keyBy(fn (Supplier $s) => mb_strtolower($s->name))->all();
    }
}
