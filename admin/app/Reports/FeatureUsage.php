<?php

namespace App\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Which installed shops use which feature, judged by the data they stored: a supplier, a bundle,
 * a manual order... Features that store nothing (what-if, purchase plan, exports) are counted by
 * the app when they are used (table `feature_events`) and reported for the last 28 days.
 *
 * Each feature names the table and columns it needs. When the deployed app doesn't have them
 * yet (an older version), the feature is reported as "not available" instead of failing.
 */
class FeatureUsage
{
    private const USED_DAYS = 28;

    public function __construct(private readonly AppData $app) {}

    /**
     * @return array<string, array{label: string, group: string, plan: string, table: string, columns: array<int, string>, where: \Closure(Builder): mixed, shop_column?: string}>
     */
    public function definitions(): array
    {
        $variants = fn (string $label, string $group, string $plan, array $columns, \Closure $where) => [
            'label' => $label, 'group' => $group, 'plan' => $plan, 'table' => 'variants', 'columns' => $columns,
            'where' => fn (Builder $q) => $where($q->where('is_active', true)),
        ];

        $used = fn (string $label, string $plan, array $features) => [
            'label' => $label, 'group' => 'Used in the last '.self::USED_DAYS.' days', 'plan' => $plan, 'table' => 'feature_events', 'columns' => ['feature', 'day'],
            'where' => fn (Builder $q) => $q->whereIn('feature', $features)->where('day', '>=', now('UTC')->subDays(self::USED_DAYS)->toDateString()),
        ];

        return [
            // Setup
            'onboarded' => ['label' => 'Finished onboarding', 'group' => 'Setup', 'plan' => 'free', 'table' => 'shops', 'columns' => ['onboarded_at'], 'shop_column' => 'id',
                'where' => fn (Builder $q) => $q->whereNotNull('onboarded_at')],
            'synced' => ['label' => 'Synced at least once', 'group' => 'Setup', 'plan' => 'free', 'table' => 'shops', 'columns' => ['last_synced_at'], 'shop_column' => 'id',
                'where' => fn (Builder $q) => $q->whereNotNull('last_synced_at')],
            'language' => ['label' => 'Chose a language', 'group' => 'Setup', 'plan' => 'free', 'table' => 'shops', 'columns' => ['locale'], 'shop_column' => 'id',
                'where' => fn (Builder $q) => $q->whereNotNull('locale')],
            'cost_override' => $variants('Unit costs entered in the app', 'Setup', 'free', ['cost_override'], fn (Builder $q) => $q->whereNotNull('cost_override')),
            'excluded_locations' => ['label' => 'Locations left out of stock', 'group' => 'Setup', 'plan' => 'free', 'table' => 'locations', 'columns' => ['excluded'],
                'where' => fn (Builder $q) => $q->where('excluded', true)],

            // Suppliers and ordering
            'suppliers' => ['label' => 'Suppliers', 'group' => 'Ordering', 'plan' => 'free', 'table' => 'suppliers', 'columns' => [],
                'where' => fn (Builder $q) => $q],
            'supplier_order_days' => ['label' => 'Supplier order weekdays', 'group' => 'Ordering', 'plan' => 'free', 'table' => 'suppliers', 'columns' => ['order_weekdays'],
                'where' => fn (Builder $q) => $q->whereNotNull('order_weekdays')],
            'supplier_order_cycle' => ['label' => 'Supplier order cycle', 'group' => 'Ordering', 'plan' => 'free', 'table' => 'suppliers', 'columns' => ['order_cycle_days'],
                'where' => fn (Builder $q) => $q->whereNotNull('order_cycle_days')],
            'moq_pack' => $variants('Minimum order / pack size', 'Ordering', 'free', ['min_order_qty', 'pack_size'],
                fn (Builder $q) => $q->where(fn ($w) => $w->whereNotNull('min_order_qty')->orWhereNotNull('pack_size'))),
            'min_max' => $variants('Manual min / max stock', 'Ordering', 'free', ['min_stock', 'max_stock'],
                fn (Builder $q) => $q->where(fn ($w) => $w->whereNotNull('min_stock')->orWhereNotNull('max_stock'))),
            'supplier_sku' => $variants('Supplier product codes', 'Ordering', 'free', ['supplier_sku'], fn (Builder $q) => $q->whereNotNull('supplier_sku')),
            'manual_orders' => ['label' => 'Marked products as ordered', 'group' => 'Ordering', 'plan' => 'free', 'table' => 'manual_orders', 'columns' => [],
                'where' => fn (Builder $q) => $q],
            'partial_deliveries' => ['label' => 'Partial deliveries', 'group' => 'Ordering', 'plan' => 'free', 'table' => 'manual_orders', 'columns' => ['received_quantity'],
                'where' => fn (Builder $q) => $q->where('received_quantity', '>', 0)->whereColumn('received_quantity', '<', 'quantity')],
            'order_budget' => ['label' => 'Order budget', 'group' => 'Ordering', 'plan' => 'starter', 'table' => 'shops', 'columns' => ['order_budget'], 'shop_column' => 'id',
                'where' => fn (Builder $q) => $q->whereNotNull('order_budget')],
            'supplier_emails' => ['label' => 'Emailed an order to a supplier', 'group' => 'Ordering', 'plan' => 'starter', 'table' => 'supplier_emails', 'columns' => [],
                'where' => fn (Builder $q) => $q],
            'supplier_auto_email' => ['label' => 'Automatic supplier orders', 'group' => 'Ordering', 'plan' => 'growth', 'table' => 'suppliers', 'columns' => ['auto_email'],
                'where' => fn (Builder $q) => $q->where('auto_email', true)],
            'transfers' => ['label' => 'Created a stock transfer', 'group' => 'Ordering', 'plan' => 'growth', 'table' => 'inventory_transfers', 'columns' => [],
                'where' => fn (Builder $q) => $q],

            // Forecast tuning
            'overrides' => ['label' => 'Forecast adjustments', 'group' => 'Forecast', 'plan' => 'free', 'table' => 'forecast_overrides', 'columns' => [],
                'where' => fn (Builder $q) => $q],
            'product_lead_time' => $variants('Per-product lead time / safety days', 'Forecast', 'free', ['lead_time_override', 'safety_days'],
                fn (Builder $q) => $q->where(fn ($w) => $w->whereNotNull('lead_time_override')->orWhereNotNull('safety_days'))),
            'discontinued' => $variants('Discontinued products', 'Forecast', 'free', ['discontinued'], fn (Builder $q) => $q->where('discontinued', true)),
            'sales_events' => ['label' => 'Sales events', 'group' => 'Forecast', 'plan' => 'free', 'table' => 'sales_events', 'columns' => [],
                'where' => fn (Builder $q) => $q],
            'spike_filter_off' => ['label' => 'Switched the spike filter off', 'group' => 'Forecast', 'plan' => 'free', 'table' => 'shops', 'columns' => ['filter_sales_spikes'], 'shop_column' => 'id',
                'where' => fn (Builder $q) => $q->where('filter_sales_spikes', false)],
            'forecast_profile' => ['label' => 'Changed the forecast profile', 'group' => 'Forecast', 'plan' => 'free', 'table' => 'shops', 'columns' => ['forecast_profile'], 'shop_column' => 'id',
                'where' => fn (Builder $q) => $q->where('forecast_profile', '!=', 'balanced')],
            'bundles' => ['label' => 'Bundles', 'group' => 'Forecast', 'plan' => 'starter', 'table' => 'bundle_components', 'columns' => [],
                'where' => fn (Builder $q) => $q],
            'reference_products' => $variants('Similar product for new products', 'Forecast', 'starter', ['reference_variant_id'], fn (Builder $q) => $q->whereNotNull('reference_variant_id')),
            'location_forecasts' => ['label' => 'Forecasts per location', 'group' => 'Forecast', 'plan' => 'growth', 'table' => 'forecasts', 'columns' => ['location_id'],
                'where' => fn (Builder $q) => $q->whereNotNull('location_id')],

            // Alerts
            'alerts' => ['label' => 'Reorder alert emails', 'group' => 'Alerts', 'plan' => 'starter', 'table' => 'alert_settings', 'columns' => ['enabled', 'email'],
                'where' => fn (Builder $q) => $q->where('enabled', true)->whereNotNull('email')],
            'weekly_summary' => ['label' => 'Weekly summary email', 'group' => 'Alerts', 'plan' => 'free', 'table' => 'alert_settings', 'columns' => ['weekly_summary'],
                'where' => fn (Builder $q) => $q->where('weekly_summary', true)],
            'slack_alerts' => ['label' => 'Alerts to Slack', 'group' => 'Alerts', 'plan' => 'starter', 'table' => 'alert_settings', 'columns' => ['slack_webhook_url'],
                'where' => fn (Builder $q) => $q->whereNotNull('slack_webhook_url')],
            'cover_days_alert' => ['label' => 'Days-of-stock alert threshold', 'group' => 'Alerts', 'plan' => 'starter', 'table' => 'alert_settings', 'columns' => ['cover_days'],
                'where' => fn (Builder $q) => $q->whereNotNull('cover_days')],
            'muted_products' => $variants('Muted alerts for a product', 'Alerts', 'starter', ['alerts_muted'], fn (Builder $q) => $q->where('alerts_muted', true)),
            'realtime_alerts' => ['label' => 'Real-time alerts', 'group' => 'Alerts', 'plan' => 'growth', 'table' => 'alert_settings', 'columns' => ['realtime'],
                'where' => fn (Builder $q) => $q->where('realtime', '!=', 'off')],
            'flow' => ['label' => 'Shopify Flow workflow', 'group' => 'Alerts', 'plan' => 'growth', 'table' => 'flow_subscriptions', 'columns' => [],
                'where' => fn (Builder $q) => $q],

            'review_prompted' => ['label' => 'Was shown the review dialog', 'group' => 'Setup', 'plan' => 'free', 'table' => 'shops', 'columns' => ['review_prompted_at', 'review_prompt_result'], 'shop_column' => 'id',
                'where' => fn (Builder $q) => $q->where('review_prompt_result', 'success')],
            'snoozed' => $variants('Snoozed a reorder suggestion', 'Ordering', 'free', ['snoozed_until'], fn (Builder $q) => $q->whereNotNull('snoozed_until')),

            // Features that store nothing: counted by the app when used (feature_events), last 28 days.
            'used_what_if' => $used('What-if scenario', 'starter', ['what_if', 'what_if_export']),
            'used_purchase_plan' => $used('Purchase plan', 'starter', ['purchase_plan', 'purchase_plan_export']),
            'used_budget' => $used('Order budget page', 'starter', ['budget']),
            'used_po_export' => $used('Exported a purchase order', 'starter', ['purchase_order_export']),
            'used_product_export' => $used('Exported the product list', 'free', ['product_export']),
            'used_accuracy' => $used('Forecast accuracy', 'free', ['accuracy']),
            'used_stock_history' => $used('Stock value history', 'free', ['stock_history']),
            'used_clearance' => $used('Clearance list', 'free', ['clearance', 'clearance_export']),
            'used_size_runs' => $used('Broken size runs', 'free', ['size_runs']),
            'used_data_health' => $used('Data check', 'free', ['data_health']),
            'used_settings_import' => $used('Imported product settings (CSV)', 'free', ['settings_import']),
            'used_change_log' => $used('Product change history', 'free', ['change_log']),
            'used_transfers' => $used('Transfer suggestions', 'growth', ['transfers']),
        ];
    }

    /**
     * Installed shops using each feature.
     *
     * @return array<string, array{label: string, group: string, plan: string, available: bool, shop_ids: array<int, int>}>
     */
    public function all(): array
    {
        return Cache::remember('report:feature-usage', (int) config('report.cache_seconds'), function () {
            $installed = array_flip($this->app->installedShops()->pluck('id')->map(fn ($id) => (int) $id)->all());
            $out = [];
            foreach ($this->definitions() as $key => $def) {
                $shopColumn = $def['shop_column'] ?? 'shop_id';
                $available = $this->app->has($def['table'], [...$def['columns'], $shopColumn]);
                $ids = [];
                if ($available) {
                    $query = $this->app->table($def['table']);
                    $def['where']($query);
                    $ids = $query->distinct()->pluck($shopColumn)->map(fn ($id) => (int) $id)
                        ->filter(fn ($id) => isset($installed[$id]))->values()->all();
                }
                $out[$key] = ['label' => $def['label'], 'group' => $def['group'], 'plan' => $def['plan'], 'available' => $available, 'shop_ids' => $ids];
            }

            return $out;
        });
    }

    /** @return array<int, string> keys of the features a shop uses */
    public function forShop(int $appShopId): array
    {
        return array_keys(array_filter($this->all(), fn ($f) => in_array($appShopId, $f['shop_ids'], true)));
    }

    public function forget(): void
    {
        Cache::forget('report:feature-usage');
    }
}
