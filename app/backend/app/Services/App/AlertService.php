<?php

namespace App\Services\App;

use App\Enums\AlertFrequency;
use App\Enums\AlertType;
use App\Enums\Feature;
use App\Jobs\PostAlertDigestToSlack;
use App\Mail\ReorderDigestMail;
use App\Models\Forecast;
use App\Models\Shop;
use App\Repositories\Contracts\AlertLogRepositoryInterface;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Services\Forecast\ExplanationFormatter;
use App\Support\Entitlements;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One summary (daily or weekly) listing what to reorder, by email and/or to a Slack channel. Never spams:
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
        if ($setting === null || ! $setting->enabled || ! $setting->hasDestination()) {
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
            ->reject(fn (Forecast $f) => $f->variant->alerts_muted || $f->variant->discontinued)
            ->sortBy([fn ($a, $b) => ($a->current_stock > 0) <=> ($b->current_stock > 0), fn ($a, $b) => $a->reorder_date <=> $b->reorder_date])
            ->values();
        // The merchant's own threshold: also products with only N days of stock left, before their reorder date.
        if ($setting->cover_days !== null && Features::on('low_cover_alerts')) {
            $items = $items->concat($this->forecasts->lowCover($shop, $local->toDateString(), $setting->cover_days)
                ->reject(fn (Forecast $f) => $f->variant->alerts_muted || $items->contains('variant_id', $f->variant_id)))->values();
        }
        $recent = $this->logs->recentVariantAlerts($shop, $now->subDays((int) config('alerts.realert_days')));
        $today = $local->toDateString();

        $rows = $items->map(function (Forecast $f) use ($recent, $today) {
            $type = match (true) {
                $f->current_stock <= 0 => AlertType::OutOfStock,
                $f->reorder_date !== null && $f->reorder_date->toDateString() <= $today => AlertType::ReorderNeeded,
                default => AlertType::LowCover,
            };
            // New = not reported lately, or worse than when it was (low cover -> reorder -> out of stock).
            $previous = AlertType::tryFrom((string) ($recent[$f->variant_id] ?? ''));
            $isNew = $previous === null || $type->severity() > $previous->severity();

            return ['forecast' => $f, 'type' => $type, 'new' => $isNew];
        });

        $new = $rows->where('new', true);
        if ($new->isEmpty()) {
            return 'nothing_new';
        }

        $listed = $rows->take((int) config('alerts.max_items'))->map(fn ($r) => $this->present($r['forecast'], $r['type'], $r['new']))->all();
        if ($setting->email) {
            Mail::to($setting->email)->queue(new ReorderDigestMail(
                shop: $shop,
                items: $listed,
                totalCount: $rows->count(),
                newCount: $new->count(),
                frequency: $setting->frequency,
            ));
        }
        if ($setting->slackUrl() !== null) {
            PostAlertDigestToSlack::dispatch($shop->id, $this->slackMessage($shop, $listed, $rows->count(), $new->count()));
        }

        $this->logs->logDigest($shop, $new->map(fn ($r) => [
            'variant_id' => $r['forecast']->variant_id,
            'type' => $r['type'],
            'stockout_date' => $r['forecast']->stockout_date?->toDateString(),
        ])->values()->all());

        Log::info('Reorder digest queued', ['shop' => $shop->domain, 'items' => $rows->count(), 'new' => $new->count()]);

        return self::SENT;
    }

    /**
     * The digest as a Slack message (same products as the email).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function slackMessage(Shop $shop, array $items, int $total, int $new): array
    {
        $title = ($total === 1 ? '1 product needs' : "{$total} products need").' attention · '.($shop->name ?? $shop->domain);
        $lines = array_map(function (array $i) {
            $state = match (true) {
                $i['out_of_stock'] => 'out of stock',
                $i['low_cover_days'] !== null => "{$i['low_cover_days']} days of stock left",
                default => "{$i['stock']} in stock, runs out {$i['stockout_date']}",
            };
            $order = $i['order_qty'] > 0 ? " → order *{$i['order_qty']}*".($i['order_by'] ? " by {$i['order_by']}" : '') : '';

            return ($i['new'] ? ':new: ' : '• ').'*'.self::slackText($i['name']).'*'.($i['sku'] ? ' ('.self::slackText($i['sku']).')' : '').": {$state}{$order}";
        }, array_slice($items, 0, (int) config('alerts.slack_max_items')));
        if ($total > count($lines)) {
            $lines[] = '…and '.($total - count($lines)).' more in the app.';
        }
        $url = "https://{$shop->domain}/admin/apps/".config('shopify.api_key').'/reorder';

        return [
            'text' => $title,
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => mb_substr($title, 0, 150)]],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr(implode("\n", $lines), 0, 2900)]],
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => "{$new} new since the last summary · <{$url}|Open ".config('shopify.app_name').'>']]],
            ],
        ];
    }

    /** Product names in Slack mrkdwn: no accidental formatting or links. */
    private static function slackText(string $text): string
    {
        return str_replace(['&', '<', '>', '*', '_', '`'], ['&amp;', '&lt;', '&gt;', '', ' ', ''], $text);
    }

    private function present(Forecast $f, AlertType $type, bool $new): array
    {
        return [
            'name' => $f->variant->displayName(),
            'sku' => $f->variant->sku,
            'out_of_stock' => $type === AlertType::OutOfStock,
            // Days of stock left, for products listed by the merchant's days-left threshold.
            'low_cover_days' => $type === AlertType::LowCover && $f->days_of_cover !== null ? (int) floor((float) $f->days_of_cover) : null,
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
