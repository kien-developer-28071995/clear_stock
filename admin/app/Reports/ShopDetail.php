<?php

namespace App\Reports;

use App\Models\ShopRecord;

/** Everything shown on one shop's page: live numbers from the app (while its data exists) and features used. */
class ShopDetail
{
    public function __construct(private readonly AppData $app, private readonly FeatureUsage $features) {}

    public function build(ShopRecord $record): array
    {
        $id = $record->app_shop_id;
        $live = $record->redacted_at === null ? $this->app->table('shops')->where('id', $id)->first() : null;
        $count = fn (string $table, ?\Closure $where = null) => $this->app->has($table, ['shop_id'])
            ? $this->app->table($table)->where('shop_id', $id)->when($where, $where)->count()
            : null;
        $used = $live === null ? [] : $this->features->forShop($id);

        return [
            'live' => $live === null ? null : [
                'timezone' => $live->timezone ?? null,
                'locale' => $live->locale ?? null,
                'sync_status' => $live->sync_status ?? null,
                'sync_error' => json_decode((string) ($live->sync_error ?? ''), true)['code'] ?? null,
                'last_synced_at' => $live->last_synced_at ?? null,
                'forecasted_at' => $live->forecasted_at ?? null,
                'subscription_status' => $live->subscription_status ?? null,
                'plan_renews_at' => $live->plan_renews_at ?? null,
                'scopes' => $live->scopes ?? null,
            ],
            'counts' => $live === null ? [] : array_filter([
                'Products tracked' => $count('variants', fn ($q) => $q->where('is_active', true)->where('tracked', true)),
                'Forecasts' => $this->app->has('forecasts', ['shop_id', 'location_id']) ? $this->app->table('forecasts')->where('shop_id', $id)->whereNull('location_id')->count() : null,
                'Locations' => $count('locations', fn ($q) => $q->where('is_active', true)),
                'Suppliers' => $count('suppliers'),
                'Open orders marked' => $count('manual_orders', fn ($q) => $q->where('status', 'open')),
                'Emails sent (180 days)' => $count('email_logs', fn ($q) => $q->where('status', 'sent')),
            ], fn ($v) => $v !== null),
            'features' => collect($this->features->all())->filter(fn ($f) => $f['available'])
                ->map(fn ($f, $key) => ['label' => $f['label'], 'group' => $f['group'], 'plan' => $f['plan'], 'used' => in_array($key, $used, true)])
                ->groupBy('group')->all(),
        ];
    }
}
