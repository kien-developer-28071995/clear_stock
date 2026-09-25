<?php

namespace App\Services\Sync;

use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Support\Gid;
use Illuminate\Support\Carbon;

/**
 * Imports the full inventory snapshot (BulkQueries::inventory). Variants whose
 * inventory item no longer exists were deleted in Shopify and are deactivated.
 */
class InventoryImporter
{
    public function __construct(private readonly CatalogRepositoryInterface $catalog) {}

    /** @return array{levels: int, deactivated: int} */
    public function import(Shop $shop, ?string $path, Carbon $startedAt): array
    {
        $variantIds = $this->catalog->variantIdMap($shop);
        $locationIds = $this->catalog->activeLocationIds($shop);

        $itemToVariant = [];   // inventory item shopify id => variants.id
        $tracked = [];
        $levelsByItem = [];    // children may appear before their parent line

        foreach ($path ? JsonlReader::read($path) : [] as $line) {
            if (isset($line['__parentId'])) {
                $available = collect($line['quantities'] ?? [])->firstWhere('name', 'available')['quantity'] ?? null;
                $location = Gid::id($line['location']['id'] ?? null);
                if ($available !== null && isset($locationIds[$location])) {
                    $levelsByItem[Gid::id($line['__parentId'])][] = [$locationIds[$location], (int) $available];
                }

                continue;
            }

            $variant = $variantIds[Gid::id($line['variant']['id'] ?? null)] ?? null;
            if ($variant !== null) {
                $itemToVariant[Gid::id($line['id'])] = $variant;
                $tracked[] = ['variant_id' => $variant, 'tracked' => (bool) $line['tracked']];
            }
        }

        $rows = [];
        foreach ($levelsByItem as $item => $levels) {
            if (! isset($itemToVariant[$item])) {
                continue;
            }
            foreach ($levels as [$locationId, $available]) {
                $rows[] = ['variant_id' => $itemToVariant[$item], 'location_id' => $locationId, 'available' => $available];
            }
        }

        $this->catalog->upsertInventoryLevels($shop, $rows);
        $this->catalog->deleteInventoryLevelsNotSeenSince($shop, $startedAt);
        $this->catalog->updateTracked($shop, $tracked);
        $deactivated = $path ? $this->catalog->deactivateVariantsNotIn($shop, array_values($itemToVariant)) : 0;

        return ['levels' => count($rows), 'deactivated' => $deactivated];
    }
}
