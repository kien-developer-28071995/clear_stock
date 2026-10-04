<?php

namespace App\Services\Sync;

/**
 * Where an order came from, in the few groups a merchant can leave out of the forecast.
 * Order.sourceName (Admin GraphQL): "web", "pos", "shopify_draft_order", "mobile_app", an app's id...
 */
final class OrderSource
{
    public const POS = 'pos';

    public const DRAFT = 'draft';

    /** Sources a merchant can exclude. */
    public const EXCLUDABLE = [self::POS, self::DRAFT];

    public static function of(?string $sourceName): ?string
    {
        return match (strtolower((string) $sourceName)) {
            'pos' => self::POS,
            'shopify_draft_order' => self::DRAFT,
            default => null,
        };
    }
}
