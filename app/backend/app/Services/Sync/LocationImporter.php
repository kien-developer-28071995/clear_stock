<?php

namespace App\Services\Sync;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Services\Shopify\AdminApiClient;
use App\Support\Gid;

/** Locations are few: a paginated regular query is cheaper than a bulk operation. */
class LocationImporter
{
    private const QUERY = <<<'GQL'
        query Locations($after: String) {
          locations(first: 250, after: $after, includeInactive: true) {
            nodes { id name isActive }
            pageInfo { hasNextPage endCursor }
          }
        }
        GQL;

    public function __construct(
        private readonly AdminApiClient $admin,
        private readonly CatalogRepositoryInterface $catalog,
    ) {}

    public function import(Shop $shop): int
    {
        $rows = [];
        $after = null;

        do {
            $page = $this->admin->query($shop, self::QUERY, ['after' => $after])['locations']
                ?? throw new ShopifyApiException('Unexpected locations response from Shopify.');
            foreach ($page['nodes'] as $node) {
                $rows[] = [
                    'shopify_location_id' => Gid::id($node['id']),
                    'name' => mb_substr((string) $node['name'], 0, 255),
                    'is_active' => (bool) $node['isActive'],
                ];
            }
            $after = $page['pageInfo']['endCursor'];
        } while ($page['pageInfo']['hasNextPage']);

        $this->catalog->upsertLocations($shop, $rows);

        return count($rows);
    }
}
