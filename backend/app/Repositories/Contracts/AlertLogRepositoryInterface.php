<?php

namespace App\Repositories\Contracts;

use App\Enums\AlertType;
use App\Models\Shop;
use Carbon\CarbonInterface;

interface AlertLogRepositoryInterface
{
    /** @return array<int, string> variant id => most recent alert type since $since */
    public function recentVariantAlerts(Shop $shop, CarbonInterface $since): array;

    public function lastDigestAt(Shop $shop): ?CarbonInterface;

    /** @param array<int, array{variant_id: int, type: string, stockout_date: ?string}> $items */
    public function logDigest(Shop $shop, array $items): void;

    /** @param array<int, array{variant_id: int, type: AlertType, stockout_date: ?string}> $items */
    public function logRealtime(Shop $shop, array $items): void;

    /** Emails of this type (Digest / Realtime) sent since $since. */
    public function countEmailsSince(Shop $shop, AlertType $type, CarbonInterface $since): int;
}
