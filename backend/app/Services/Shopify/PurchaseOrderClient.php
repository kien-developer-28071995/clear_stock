<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Support\Gid;

/**
 * Shopify's own purchase orders (Products > Purchase orders), read-only. Admin GraphQL 2026-10,
 * optional scope `read_inventory_purchase_orders`. Field names checked against the 2026-10 docs:
 * InventoryPurchaseOrder {id name status orderedAt dateCreated currency origin{supplierName}
 * lineItems{title variantTitle supplierSku totalQuantity unitCost{amount} inventoryItem{id}}}.
 * The API gives no expected arrival date and no received quantity per line.
 */
class PurchaseOrderClient
{
    public const API_VERSION = '2026-10';

    public const SCOPE = 'read_inventory_purchase_orders';

    private const QUERY = <<<'GQL'
        query OpenPurchaseOrders($first: Int!) {
          inventoryPurchaseOrders(first: $first) {
            nodes {
              id name status orderedAt dateCreated currency archivedAt
              origin { supplierName }
              lineItems(first: 100) {
                nodes { title variantTitle supplierSku totalQuantity unitCost { amount } inventoryItem { id } }
              }
            }
          }
        }
        GQL;

    public function __construct(private readonly AdminApiClient $admin) {}

    /**
     * Open purchase orders marked as ordered (drafts are not on the way yet).
     *
     * @return array<int, array{id: int, name: string, supplier: ?string, ordered_on: ?string, currency: ?string, lines: array<int, array{inventory_item_id: ?int, title: ?string, supplier_sku: ?string, quantity: int, unit_cost: ?float}>}>
     *
     * @throws ShopifyApiException
     */
    public function ordered(Shop $shop, int $limit = 50): array
    {
        $data = $this->admin->query($shop, self::QUERY, ['first' => $limit], self::API_VERSION);
        $out = [];
        foreach ($data['inventoryPurchaseOrders']['nodes'] ?? [] as $po) {
            if (($po['status'] ?? null) !== 'ORDERED' || ($po['archivedAt'] ?? null) !== null) {
                continue;
            }
            $out[] = [
                'id' => Gid::id($po['id']),
                'name' => (string) $po['name'],
                'supplier' => $po['origin']['supplierName'] ?? null,
                'ordered_on' => isset($po['orderedAt']) ? substr((string) $po['orderedAt'], 0, 10) : (isset($po['dateCreated']) ? substr((string) $po['dateCreated'], 0, 10) : null),
                'currency' => $po['currency'] ?? null,
                'lines' => array_map(fn (array $l) => [
                    'inventory_item_id' => isset($l['inventoryItem']['id']) ? Gid::id($l['inventoryItem']['id']) : null,
                    'title' => trim(($l['title'] ?? '').(($l['variantTitle'] ?? '') !== '' && ($l['variantTitle'] ?? '') !== 'Default Title' ? ' - '.$l['variantTitle'] : '')) ?: null,
                    'supplier_sku' => $l['supplierSku'] ?? null,
                    'quantity' => (int) ($l['totalQuantity'] ?? 0),
                    'unit_cost' => isset($l['unitCost']['amount']) ? (float) $l['unitCost']['amount'] : null,
                ], $po['lineItems']['nodes'] ?? []),
            ];
        }

        return $out;
    }
}
