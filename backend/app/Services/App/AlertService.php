<?php

namespace App\Services\App;

use App\Enums\AlertFrequency;
use App\Enums\AlertType;
use App\Enums\Feature;
use App\Mail\ReorderDigestMail;
use App\Models\Forecast;
use App\Models\Shop;
use App\Repositories\Contracts\AlertLogRepositoryInterface;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Services\Forecast\ExplanationFormatter;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One summary email (daily or weekly) listing what to reorder. Never spams:
 * nothing is sent unless at least one product is new since the last digests,
 * and never more than once a day.
 */
class AlertService
{
    public const SENT = 'sent';

    public function __construct(
        private readonly AlertSettingRepositoryInterface $settings,
        private readonly AlertLogRepositoryInterface $logs,
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly ExplanationFormatter $formatter,
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
        if (! Entitlements::for($shop)->has(Feature::Alerts)) {
            return 'plan';
        }
        if ($setting === null || ! $setting->enabled || ! $setting->email) {
            return 'disabled';
        }
        if ($local->hour < (int) config('alerts.send_hour')) {
            return 'too_early';
        }
        if ($setting->frequency === AlertFrequency::Weekly && $local->isoWeekday() !== $setting->weekly_day) {
            return 'not_this_weekday';
        }
        $last = $this->logs->lastDigestAt($shop);
        if ($last !== null && CarbonImmutable::parse($last)->setTimezone($shop->timezone)->isSameDay($local)) {
            return 'already_sent_today';
        }
        if ($shop->forecasted_at === null || $shop->forecasted_at->lt($now->subHours((int) config('alerts.max_forecast_age_hours')))) {
            return 'stale_forecast';
        }

        $items = $this->forecasts->reorderList($shop, $local->toDateString(), null)
            ->reject(fn (Forecast $f) => $f->variant->alerts_muted)
            ->sortBy([fn ($a, $b) => ($a->current_stock > 0) <=> ($b->current_stock > 0), fn ($a, $b) => $a->reorder_date <=> $b->reorder_date])
            ->values();
        $recent = $this->logs->recentVariantAlerts($shop, $now->subDays((int) config('alerts.realert_days')));

        $rows = $items->map(function (Forecast $f) use ($recent) {
            $type = $f->current_stock <= 0 ? AlertType::OutOfStock : AlertType::ReorderNeeded;
            $previous = $recent[$f->variant_id] ?? null;
            $isNew = $previous === null || ($type === AlertType::OutOfStock && $previous === AlertType::ReorderNeeded->value);

            return ['forecast' => $f, 'type' => $type, 'new' => $isNew];
        });

        $new = $rows->where('new', true);
        if ($new->isEmpty()) {
            return 'nothing_new';
        }

        Mail::to($setting->email)->queue(new ReorderDigestMail(
            shop: $shop,
            items: $rows->take((int) config('alerts.max_items'))->map(fn ($r) => $this->present($r['forecast'], $r['type'], $r['new']))->all(),
            totalCount: $rows->count(),
            newCount: $new->count(),
            frequency: $setting->frequency,
        ));

        $this->logs->logDigest($shop, $new->map(fn ($r) => [
            'variant_id' => $r['forecast']->variant_id,
            'type' => $r['type'],
            'stockout_date' => $r['forecast']->stockout_date?->toDateString(),
        ])->values()->all());

        Log::info('Reorder digest queued', ['shop' => $shop->domain, 'items' => $rows->count(), 'new' => $new->count()]);

        return self::SENT;
    }

    private function present(Forecast $f, AlertType $type, bool $new): array
    {
        return [
            'name' => $f->variant->displayName(),
            'sku' => $f->variant->sku,
            'out_of_stock' => $type === AlertType::OutOfStock,
            'new' => $new,
            'stock' => $f->current_stock,
            'stockout_date' => $f->stockout_date?->format('M j'),
            'order_qty' => $f->suggested_qty,
            'order_by' => $f->reorder_date?->format('M j'),
            // Emails are English: render the first explanation line with lang/en/explanation.php.
            'why' => $this->formatter->sentences($f->explanation, 'en')[0] ?? null,
        ];
    }
}
