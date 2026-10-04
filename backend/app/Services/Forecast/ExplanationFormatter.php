<?php

namespace App\Services\Forecast;

use App\Enums\Feature;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;

/**
 * Turns a forecast explanation (JSON stored on forecasts.explanation) into lines of
 * the form {code, params}. The API returns these lines as-is and the React UI
 * translates them; emails and the CLI render them with `sentences()`.
 *
 * Params are raw values (numbers, Y-m-d dates, names). A param can itself be a line
 * ({code, params}) or a list of lines, e.g. the lead-time source or confidence reasons.
 * `count` is the value that picks the plural form.
 */
class ExplanationFormatter
{
    /** @return array<int, array{code: string, params: array<string, mixed>}> */
    public function lines(array $e): array
    {
        $out = [];

        // Average
        if ($e['avg_source'] === 'override') {
            $note = collect($e['overrides'])->firstWhere('field', 'avg_daily_sales')['note'] ?? null;
            $out[] = $note
                ? $this->line('avg_override_note', ['avg' => $this->round($e['avg_daily_sales']), 'computed' => $this->round($e['computed_avg']), 'note' => $note])
                : $this->line('avg_override', ['avg' => $this->round($e['avg_daily_sales']), 'computed' => $this->round($e['computed_avg'])]);
        } else {
            $main = collect($e['windows'])->firstWhere('days', 30);
            $main = ($main['avg'] ?? null) !== null ? $main : collect($e['windows'])->first(fn ($w) => $w['avg'] !== null);
            $longest = collect($e['windows'])->filter(fn ($w) => $w['in_stock_days'] > 0)->last();
            if ($main === null && $longest === null) {
                $out[] = $this->line('no_rate_yet');
            } elseif ((float) $e['base_avg'] === 0.0) {
                $out[] = $this->line('no_sales_while_in_stock', ['count' => $longest['days']]);
            } elseif ($main === null) {
                $out[] = $this->line('no_rate_yet');
            } else {
                $out[] = $main['excluded_out_of_stock_days'] > 0
                    ? $this->line('sells_over_window_excluded', ['avg' => $this->round($main['avg']), 'days' => $main['days'], 'count' => $main['excluded_out_of_stock_days']])
                    : $this->line('sells_over_window', ['avg' => $this->round($main['avg']), 'count' => $main['days']]);

                $usable = collect($e['windows'])->filter(fn ($w) => $w['avg'] !== null)->values();
                // Only worth a line when the windows visibly disagree.
                if ($usable->count() > 1 && $usable->map(fn ($w) => $this->round($w['avg']))->unique()->count() > 1) {
                    $out[] = $this->line('blended_rate', [
                        'avg' => $this->round($e['base_avg']),
                        'parts' => $usable->map(fn ($w) => $this->line('window_part', [
                            'count' => $w['days'], 'avg' => $this->round($w['avg']), 'weight' => (int) round($w['weight'] * 100),
                        ]))->all(),
                    ]);
                }
            }
        }

        // Forecast profile other than the default, and the recent trend (absent in older explanations)
        $profile = $e['profile']['name'] ?? 'balanced';
        if ($e['avg_source'] !== 'override' && $profile !== 'balanced') {
            $out[] = $this->line('profile_'.$profile.(($e['profile']['source'] ?? 'shop') === 'variant' ? '_product' : ''));
        }
        $trend = $e['trend'] ?? null;
        if ($trend !== null && $trend['direction'] !== 'flat') {
            $out[] = $this->line('trend_'.$trend['direction'], [
                'percent' => abs($trend['percent']), 'count' => $trend['recent_days'],
                'recent' => $this->round($trend['recent_avg']), 'baseline' => $this->round($trend['baseline_avg']), 'days' => $trend['baseline_days'],
            ]);
        }

        // One-off spikes capped (absent in explanations computed before it existed)
        $spikes = $e['spikes'] ?? null;
        if ($e['avg_source'] !== 'override' && ($spikes['applied'] ?? false)) {
            $top = $spikes['days'][0];
            $out[] = $this->line('spikes_capped', [
                'count' => count($spikes['days']), 'units' => $this->round($spikes['units_removed']),
                'top' => $this->round($top['units']), 'top_date' => $top['date'], 'usual' => $this->round($top['usual']),
            ]);
        }

        // Sales events the merchant entered (absent in older explanations)
        foreach ($e['events']['past'] ?? [] as $ev) {
            if ($e['avg_source'] !== 'override') {
                $out[] = $this->line('event_past', ['name' => $ev['name'], 'count' => $ev['days'], 'multiplier' => $this->round($ev['multiplier'], 2)]);
            }
        }
        foreach ($e['events']['upcoming'] ?? [] as $ev) {
            $out[] = $this->line($ev['units_order'] >= 0 ? 'event_upcoming' : 'event_upcoming_lower', [
                'name' => $ev['name'], 'from_date' => $ev['from'], 'to_date' => $ev['to'],
                'multiplier' => $this->round($ev['multiplier'], 2), 'count' => (int) round(abs($ev['units_order'])),
            ]);
        }

        // Seasonality
        $s = $e['seasonality'];
        if ($s['applied']) {
            $out[] = $this->line($s['factor'] >= 1 ? 'seasonality_rose' : 'seasonality_fell', [
                'factor' => $this->round($s['factor'], 2), 'count' => $s['horizon_days'], 'avg' => $this->round($e['own_avg'] ?? $e['computed_avg']),
            ]);
        }

        // Bundles
        foreach ($e['bundles'] as $b) {
            if ($b['units_per_day'] > 0) {
                $out[] = $this->line('bundle_contribution', ['avg' => $this->round($b['units_per_day']), 'bundle' => $b['name'], 'count' => $b['quantity_per_bundle']]);
            }
        }

        // New product: similar product's rate (absent in explanations computed before it existed)
        $ref = $e['reference'] ?? null;
        if ($ref !== null && $e['avg_source'] !== 'override') {
            $out[] = match (true) {
                $ref['applied'] => $this->line('reference_blend', [
                    'name' => $ref['name'], 'ref_avg' => $this->round($ref['reference_avg']), 'percent' => $ref['percent'],
                    'count' => $ref['own_days'], 'ref_share' => (int) round((1 - $ref['own_weight']) * 100), 'avg' => $this->round($e['computed_avg']),
                ]),
                ($ref['reason'] ?? null) === 'enough_history' => $this->line('reference_done', ['name' => $ref['name'], 'count' => $ref['own_days']]),
                default => $this->line('reference_no_forecast', ['name' => $ref['name']]),
            };
        }

        // Reorder
        $min = $e['reorder']['min_stock'] ?? null; // absent in explanations computed before min/max existed
        $max = $e['reorder']['max_stock'] ?? null;
        if ($e['discontinued'] ?? false) {
            // No longer reordered: only how long what is left lasts.
            $stock = $e['stock']['current'];
            $out[] = match (true) {
                $stock <= 0 => $this->line('discontinued_sold_out'),
                $e['stockout_date'] === null => $this->line('discontinued_no_sales', ['count' => $stock]),
                default => $this->line('discontinued_sells_through', ['count' => $stock, 'stockout_date' => $e['stockout_date']]),
            };
        } elseif ($e['avg_daily_sales'] > 0 || $min !== null) {
            if ($min !== null) {
                $out[] = $this->line('reorder_point_manual', ['count' => $min]);
            } else {
                $lead = $e['lead_time'];
                $out[] = $this->line('reorder_point', [
                    'lead_days' => $lead['days'],
                    'lead_source' => $this->line('lead_source_'.$lead['source'], array_filter(['supplier' => $lead['supplier'] ?? null])),
                    'safety_days' => $e['safety']['days'],
                    'count' => $e['reorder']['point'],
                ]);
            }
            if ($max !== null) {
                $out[] = $this->line('order_up_to_max', ['count' => $max]);
            } elseif (($e['reorder']['order_cycle_source'] ?? null) === 'supplier') {
                $out[] = $this->line('order_cycle_supplier', ['count' => $e['reorder']['order_cycle_days'], 'supplier' => $e['reorder']['order_cycle_supplier']]);
            }

            // Supplier's order weekdays moved the order date back (absent in older explanations).
            $days = $e['reorder']['order_weekdays'] ?? null;
            if ($days !== null && $days['due_date'] !== null && $e['reorder']['date'] !== $days['due_date']) {
                $out[] = $this->line('order_weekday_moved', ['supplier' => $days['supplier'], 'reorder_date' => $e['reorder']['date'], 'due_date' => $days['due_date']]);
            }

            $stock = $e['stock']['current'];
            $incoming = $e['stock']['incoming'] ?? 0; // absent in explanations computed before it existed
            $suggested = $e['reorder']['suggested_qty'];
            $ordered = $e['stock']['ordered'] ?? null; // placed outside Shopify (absent in older explanations)
            if ($incoming - ($ordered['units'] ?? 0) > 0) {
                $out[] = $this->line('incoming_stock', ['count' => $incoming - ($ordered['units'] ?? 0)]);
            }
            if ($ordered !== null) {
                $out[] = $this->line('ordered_manual', ['count' => $ordered['units'], 'expected_date' => $ordered['expected_on']]);
            }
            $r = $e['reorder']['rounding'] ?? null; // absent in explanations computed before it existed
            if ($r !== null && $r['final'] !== $r['needed']) {
                $out[] = $this->roundingLine($r);
                if (isset($r['supplier'])) {
                    $out[] = $this->line('rounding_supplier_default', ['supplier' => $r['supplier']]);
                }
            }
            $out[] = match (true) {
                $stock <= 0 && $suggested > 0 => $this->line('order_today_out_of_stock', ['count' => $suggested]),
                $stock <= 0 && $incoming > 0 => $this->line('out_of_stock_incoming_covers'),
                // No sales: only the manual minimum decides.
                $e['stockout_date'] === null => match (true) {
                    $e['reorder']['date'] === null => $this->line('above_min', ['stock' => $e['stock']['position'] ?? $stock]),
                    $suggested > 0 => $this->line('below_min_order', ['stock' => $e['stock']['position'] ?? $stock, 'count' => $suggested]),
                    default => $this->line('no_order_needed'),
                },
                $incoming > 0 && $suggested === 0 => $this->line('runs_out_incoming_covers', ['stock' => $stock, 'stockout_date' => $e['stockout_date']]),
                default => $this->line('runs_out', [
                    'stock' => $stock, 'stockout_date' => $e['stockout_date'],
                    'count' => $suggested, 'reorder_date' => $e['reorder']['date'],
                ]),
            };
        }

        // Overstock: holding clearly more than needed (still selling).
        if (($e['reorder']['overstock'] ?? false) === true) {
            $out[] = $this->line('overstock', ['count' => $e['reorder']['excess'], 'target' => $e['reorder']['target']]);
        }

        // Sales missed while out of stock (absent in explanations computed before it existed)
        $lost = $e['lost_sales'] ?? null;
        if ($lost !== null && $lost['units'] > 0 && Features::enabled(Feature::LostSales)) {
            $out[] = $this->line('lost_sales', ['count' => $lost['out_of_stock_days'], 'units' => $this->round($lost['units']), 'days' => $lost['days']]);
        }

        // Confidence
        $c = $e['confidence'];
        $reasons = collect($c['reasons'])->map(fn ($r) => match ($r['code']) {
            'little_history' => $this->line('reason_little_history', ['count' => $r['in_stock_days']]),
            'few_sales' => $r['units'] > 0 ? $this->line('reason_few_sales', ['count' => $this->round($r['units'])]) : $this->line('reason_no_sales'),
            default => $this->line('reason_'.$r['code']),
        })->all();
        $level = $this->line('confidence_'.$c['level']);
        $out[] = $reasons === []
            ? $this->line('confidence', ['level' => $level])
            : $this->line('confidence_with_reasons', ['level' => $level, 'reasons' => $reasons]);

        return $out;
    }

    /**
     * Plain sentences in a language, from lang/{locale}/explanation.php.
     * For emails and the CLI; the API returns `lines()`.
     *
     * @return array<int, string>
     */
    public function sentences(array $e, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return array_map(fn (array $line) => $this->render($line, $locale), $this->lines($e));
    }

    /** @param array{code: string, params: array<string, mixed>} $line */
    public function render(array $line, string $locale): string
    {
        $params = [];
        foreach ($line['params'] as $key => $value) {
            $params[$key] = match (true) {
                is_array($value) && isset($value['code']) => $this->render($value, $locale),
                is_array($value) => implode(', ', array_map(fn ($l) => $this->render($l, $locale), $value)),
                is_int($value) || is_float($value) => $this->number($value, $locale),
                is_string($value) && str_ends_with($key, '_date') => $this->formatDate($value, $locale),
                default => (string) $value,
            };
        }

        $key = "explanation.{$line['code']}";

        return isset($line['params']['count'])
            ? trans_choice($key, $line['params']['count'], $params, $locale)
            : __($key, $params, $locale);
    }

    /** @return array{code: string, params: array<string, mixed>} */
    private function roundingLine(array $r): array
    {
        $raisedToMinimum = $r['min_order_qty'] !== null && $r['needed'] < $r['min_order_qty'];
        $params = ['needed' => $r['needed'], 'count' => $r['final']];

        return match (true) {
            $raisedToMinimum && $r['pack_size'] !== null => $this->line('rounded_min_and_pack', $params + ['min' => $r['min_order_qty'], 'pack' => $r['pack_size']]),
            $raisedToMinimum => $this->line('rounded_min', $params + ['min' => $r['min_order_qty']]),
            default => $this->line('rounded_pack', $params + ['pack' => $r['pack_size']]),
        };
    }

    /** @return array{code: string, params: array<string, mixed>} */
    private function line(string $code, array $params = []): array
    {
        return ['code' => $code, 'params' => $params];
    }

    private function round(float|int|null $n, int $precision = 1): float|int
    {
        $r = round((float) $n, $precision);

        return floor($r) === $r ? (int) $r : $r;
    }

    private function number(float|int $n, string $locale): string
    {
        return Number::format($n, maxPrecision: 2, locale: $locale) ?: (string) $n;
    }

    /** Short day + month in a language ("Oct 15", "15/10"). */
    public function formatDate(string $date, string $locale): string
    {
        return CarbonImmutable::parse($date)->locale($locale)->isoFormat(__('explanation.date_format', [], $locale));
    }
}
