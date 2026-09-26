<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use Carbon\CarbonInterface;

interface AlertLogRepositoryInterface
{
    /** @return array<int, string> variant id => most recent alert type since $since */
    public function recentVariantAlerts(Shop $shop, CarbonInterface $since): array;

    public function lastDigestAt(Shop $shop): ?CarbonInterface;

    /** @param array<int, array{variant_id: int, type: string, stockout_date: ?string}> $items */
    public function logDigest(Shop $shop, array $items): void;
}
