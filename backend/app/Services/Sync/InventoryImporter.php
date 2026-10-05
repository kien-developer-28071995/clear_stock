<?php

namespace App\Services\Sync;

use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Support\Gid;
use App\Support\Payload;
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
                // name => quantity of the well-formed entries only.
                $quantities = [];
                foreach (is_array($line['quantities'] ?? null) ? $line['quantities'] : [] as $entry) {
                    if (is_array($entry) && is_string($entry['name'] ?? null) && Payload::int($entry['quantity'] ?? null) !== null) {
                        $quantities[$entry['name']] = Payload::int($entry['quantity'], -2_000_000_000, 2_000_000_000);
                    }
                }
                $location = Gid::id(Payload::get($line, 'location', 'id'));
                $item = Gid::id($line['__parentId']);
                if (isset($quantities['available']) && $item !== null && isset($locationIds[$location])) {
                    $levelsByItem[$item][] = [$locationIds[$location], $quantities['available'], max(0, $quantities['incoming'] ?? 0)];
                }

                continue;
            }

            $variant = $variantIds[Gid::id(Payload::get($line, 'variant', 'id'))] ?? null;
            $item = Gid::id($line['id'] ?? null);
            if ($variant !== null && $item !== null) {
                $itemToVariant[$item] = $variant;
                $tracked[] = ['variant_id' => $variant, 'tracked' => ($line['tracked'] ?? false) === true];
            }
        }

        $rows = [];
        foreach ($levelsByItem as $item => $levels) {
            if (! isset($itemToVariant[$item])) {
                continue;
            }
            foreach ($levels as [$locationId, $available, $incoming]) {
                $rows[] = ['variant_id' => $itemToVariant[$item], 'location_id' => $locationId, 'available' => $available, 'incoming' => $incoming];
            }
        }

        $this->catalog->upsertInventoryLevels($shop, $rows);
        $this->catalog->deleteInventoryLevelsNotSeenSince($shop, $startedAt);
        $this->catalog->updateTracked($shop, $tracked);
        $deactivated = $path ? $this->catalog->deactivateVariantsNotIn($shop, array_values($itemToVariant)) : 0;

        return ['levels' => count($rows), 'deactivated' => $deactivated];
    }
}
