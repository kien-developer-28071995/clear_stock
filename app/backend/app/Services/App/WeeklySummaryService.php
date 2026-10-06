<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Mail\WeeklySummaryMail;
use App\Models\Forecast;
use App\Models\Shop;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Repositories\Contracts\InventorySnapshotRepositoryInterface;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Weekly summary email (every plan, opt-in): where the stock stands, in one email a week on the
 * merchant's chosen weekday. Unlike the reorder digest it goes out even when nothing is new.
 */
class WeeklySummaryService
{
    public const SENT = 'sent';

    private const TOP = 5;

    public function __construct(
        private readonly AlertSettingRepositoryInterface $settings,
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly InventorySnapshotRepositoryInterface $snapshots,
        private readonly ForecastAccuracyService $accuracy,
    ) {}

    /** @return string self::SENT or the reason nothing was sent */
    public function sendIfDue(Shop $shop, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        $local = $now->setTimezone($shop->timezone);
        $setting = $this->settings->forShop($shop);

        if (! $shop->isInstalled()) {
            return 'not_installed';
        }
        if (! Entitlements::for($shop)->has(Feature::WeeklySummary)) {
            return 'feature_off';
        }
        if ($setting === null || ! $setting->weekly_summary || ! $setting->email) {
            return 'disabled';
        }
        if ($local->isoWeekday() !== $setting->weekly_day) {
            return 'not_this_weekday';
        }
        if ($local->hour < (int) config('alerts.send_hour')) {
            return 'too_early';
        }
        // Once a week, whatever happens to the clock or the chosen weekday.
        if ($setting->weekly_summary_sent_at !== null && $setting->weekly_summary_sent_at->gt($now->subDays(6))) {
            return 'already_sent_this_week';
        }
        if ($shop->forecasted_at === null || $shop->forecasted_at->lt($now->subHours((int) config('alerts.max_forecast_age_hours')))) {
            return 'stale_forecast';
        }

        $summary = $this->build($shop, $local);
        if ($summary['counts']['total'] === 0) {
            return 'no_forecasts';
        }

        Mail::to($setting->email)->queue(new WeeklySummaryMail($shop, $summary));
        $this->settings->upsert($shop, ['weekly_summary_sent_at' => $now]);
        Log::info('Weekly summary queued', ['shop' => $shop->domain]);

        return self::SENT;
    }

    /** Everything the email shows (plain numbers; the mail view formats them). */
    public function build(Shop $shop, CarbonImmutable $local): array
    {
        $today = $local->toDateString();
        $entitlements = Entitlements::for($shop);
        $slow = $this->forecasts->slowMovers($shop, 0);
        $overstock = $this->forecasts->overstock($shop, $today, 0);
        $lost = $entitlements->has(Feature::LostSales) ? $this->forecasts->lostSales($shop, 0) : null;
        $accuracy = $entitlements->has(Feature::Accuracy) ? ($this->accuracy->report($shop)['latest']['accuracy'] ?? null) : null;

        // Inventory value now and about a week ago.
        $points = $this->snapshots->since($shop, $local->subDays(10)->toDateString());
        $latest = $points === [] ? null : end($points);
        $weekAgo = collect($points)->last(fn ($p) => $p['date'] <= $local->subDays(7)->toDateString());

        return [
            'counts' => $this->forecasts->counts($shop, $today),
            'to_order' => $this->forecasts->actionItems($shop, $local->addDays(7)->toDateString(), self::TOP)->map(fn (Forecast $f) => [
                'name' => $f->variant->displayName(),
                'sku' => $f->variant->sku,
                'stock' => $f->current_stock,
                'out_of_stock' => $f->current_stock <= 0,
                'stockout_date' => $f->stockout_date?->format('M j'),
                'order_qty' => $f->suggested_qty,
                'order_by' => $f->reorder_date?->format('M j'),
            ])->all(),
            'tied_up' => ['value' => round($slow['value'] + $overstock['value'], 2), 'products' => $slow['count'] + $overstock['count']],
            'lost_sales' => $lost === null ? null : ['revenue' => $lost['revenue'], 'units' => $lost['units'], 'products' => $lost['count']],
            'inventory' => $latest === null ? null : [
                'value' => $latest['value'],
                'units' => $latest['units'],
                'value_change' => $weekAgo !== null && $weekAgo['date'] !== $latest['date'] ? round($latest['value'] - $weekAgo['value'], 2) : null,
            ],
            'accuracy' => $accuracy,
        ];
    }
}
