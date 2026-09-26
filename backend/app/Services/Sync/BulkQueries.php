<?php

namespace App\Services\Sync;

/**
 * Bulk query documents. Limits: <= 5 connections, <= 2 nesting levels.
 * Field names checked against Admin GraphQL API 2026-07.
 */
final class BulkQueries
{
    /** Variants with product, price, cost, tracking and native bundle components. */
    public static function variants(?string $updatedSinceIso = null): string
    {
        $filter = $updatedSinceIso ? sprintf('(query: "updated_at:>=\'%s\'")', $updatedSinceIso) : '';

        return <<<GQL
            {
              productVariants{$filter} {
                edges { node {
                  id sku barcode title price createdAt requiresComponents
                  product { id title status vendor productType }
                  inventoryItem { id tracked unitCost { amount } }
                  productVariantComponents { edges { node { quantity productVariant { id } } } }
                } }
              }
            }
            GQL;
    }

    /** Current available and incoming (on the way) quantities per inventory item and location (full snapshot). */
    public static function inventory(): string
    {
        return <<<'GQL'
            {
              inventoryItems {
                edges { node {
                  id tracked
                  variant { id }
                  inventoryLevels { edges { node {
                    location { id }
                    quantities(names: ["available", "incoming"]) { name quantity }
                  } } }
                } }
              }
            }
            GQL;
    }

    /**
     * Order line quantities since a date. Only what the forecast needs:
     * no customer, address or price fields are requested.
     */
    public static function orders(string $processedSinceIso, bool $withLocations = false): string
    {
        // Growth / multi-location: which location each unit is fulfilled from
        // (needs the read_*_fulfillment_orders scopes). 4 connections, 2 levels deep.
        $locations = $withLocations ? <<<'GQL'
                  fulfillmentOrders { edges { node {
                    id status
                    assignedLocation { location { id } }
                    lineItems { edges { node { totalQuantity variant { id } } } }
                  } } }
            GQL : '';

        return <<<GQL
            {
              orders(query: "processed_at:>='{$processedSinceIso}'") {
                edges { node {
                  id processedAt cancelledAt
                  lineItems { edges { node {
                    quantity currentQuantity
                    variant { id }
                    lineItemGroup { id quantity variantId }
                  } } }
            {$locations}
                } }
              }
            }
            GQL;
    }
}
