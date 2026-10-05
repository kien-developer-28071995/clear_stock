<?php

namespace App\Repositories\Eloquent;

use App\Enums\AlertType;
use App\Models\AlertLog;
use App\Models\Shop;
use App\Repositories\Contracts\AlertLogRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class EloquentAlertLogRepository implements AlertLogRepositoryInterface
{
    public function recentVariantAlerts(Shop $shop, CarbonInterface $since): array
    {
        return AlertLog::query()->forShop($shop)->whereNotNull('variant_id')->where('sent_at', '>=', $since)
            ->orderBy('sent_at')->orderBy('id')->get(['variant_id', 'type']) // id: two alerts in the same second keep their order
            ->mapWithKeys(fn (AlertLog $l) => [$l->variant_id => $l->type->value])->all();
    }

    public function lastDigestAt(Shop $shop): ?CarbonInterface
    {
        return AlertLog::query()->forShop($shop)->where('type', AlertType::Digest)->latest('sent_at')->value('sent_at');
    }

    public function logDigest(Shop $shop, array $items): void
    {
        $this->logEmail($shop, AlertType::Digest, $items);
    }

    public function logRealtime(Shop $shop, array $items): void
    {
        $this->logEmail($shop, AlertType::Realtime, $items);
    }

    public function countEmailsSince(Shop $shop, AlertType $type, CarbonInterface $since): int
    {
        return AlertLog::query()->forShop($shop)->where('type', $type)->where('sent_at', '>=', $since)->count();
    }

    /** One row for the email itself, one per product it reported (drives the re-alert cooldown). */
    private function logEmail(Shop $shop, AlertType $email, array $items): void
    {
        $now = now();
        DB::transaction(function () use ($shop, $email, $items, $now) {
            AlertLog::query()->create(['shop_id' => $shop->id, 'type' => $email, 'sent_at' => $now]);
            foreach ($items as $item) {
                AlertLog::query()->create($item + ['shop_id' => $shop->id, 'sent_at' => $now]);
            }
        });
    }
}
