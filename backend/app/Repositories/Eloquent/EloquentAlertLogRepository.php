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
            ->orderBy('sent_at')->get(['variant_id', 'type'])
            ->mapWithKeys(fn (AlertLog $l) => [$l->variant_id => $l->type->value])->all();
    }

    public function lastDigestAt(Shop $shop): ?CarbonInterface
    {
        return AlertLog::query()->forShop($shop)->where('type', AlertType::Digest)->latest('sent_at')->value('sent_at');
    }

    public function logDigest(Shop $shop, array $items): void
    {
        $now = now();
        DB::transaction(function () use ($shop, $items, $now) {
            AlertLog::query()->create(['shop_id' => $shop->id, 'type' => AlertType::Digest, 'sent_at' => $now]);
            foreach ($items as $item) {
                AlertLog::query()->create($item + ['shop_id' => $shop->id, 'sent_at' => $now]);
            }
        });
    }
}
