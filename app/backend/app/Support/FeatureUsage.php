<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Counts uses of features that store nothing (what-if, purchase plan, exports), so the owner's
 * reports can tell whether anyone uses them. One row per shop, feature and UTC day; nothing
 * about what was looked at. A failure here never fails the merchant's request.
 */
final class FeatureUsage
{
    /** Features that may be recorded (route middleware `usage:<name>`). */
    public const FEATURES = [
        'what_if', 'what_if_export', 'purchase_plan', 'purchase_plan_export', 'budget', 'accuracy', 'stock_history',
        'data_health', 'clearance', 'clearance_export', 'size_runs', 'product_export', 'purchase_order_export',
        'transfers', 'change_log', 'settings_import',
    ];

    public static function record(Shop $shop, string $feature): void
    {
        if (! in_array($feature, self::FEATURES, true)) {
            return;
        }
        try {
            DB::table('feature_events')->upsert(
                [['shop_id' => $shop->id, 'feature' => $feature, 'day' => now('UTC')->toDateString(), 'count' => 1]],
                ['shop_id', 'feature', 'day'],
                ['count' => DB::raw('feature_events.count + 1')],
            );
        } catch (Throwable $e) {
            Monitor::caught($e, 'FeatureUsage::record', ['feature' => $feature]);
        }
    }
}
