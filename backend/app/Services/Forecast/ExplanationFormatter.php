<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;

/**
 * Turns a forecast explanation (JSON stored on forecasts.explanation) into plain
 * sentences. Used by the CLI and alert emails; the React UI renders the same
 * data with its own components.
 */
class ExplanationFormatter
{
    private const LEAD_SOURCES = [
        'override' => 'set by you for this forecast',
        'variant' => 'set for this SKU',
        'supplier' => 'supplier %s',
        'shop_default' => 'store default',
    ];

    /** @return array<int, string> */
    public function sentences(array $e): array
    {
        $out = [];
        $avg = $this->num($e['avg_daily_sales']);

        // Average
        if ($e['avg_source'] === 'override') {
            $note = collect($e['overrides'])->firstWhere('field', 'avg_daily_sales')['note'] ?? null;
            $out[] = "You set sales to {$avg}/day".($note ? " ({$note})" : '').'; our estimate was '.$this->num($e['computed_avg']).'/day.';
        } else {
            $main = collect($e['windows'])->firstWhere('days', 30);
            $main = ($main['avg'] ?? null) !== null ? $main : collect($e['windows'])->first(fn ($w) => $w['avg'] !== null);
            $longest = collect($e['windows'])->filter(fn ($w) => $w['in_stock_days'] > 0)->last();
            if ($main === null && $longest === null) {
                $out[] = 'Not enough in-stock days yet to measure a sales rate.';
            } elseif ((float) $e['base_avg'] === 0.0) {
                $out[] = "No sales in the last {$longest['days']} days while in stock.";
            } elseif ($main === null) {
                $out[] = 'Not enough in-stock days yet to measure a sales rate.';
            } else {
                $excluded = $main['excluded_out_of_stock_days'] > 0
                    ? " ({$main['excluded_out_of_stock_days']} out-of-stock ".($main['excluded_out_of_stock_days'] === 1 ? 'day' : 'days').' left out)'
                    : '';
                $out[] = 'Sells '.$this->num($main['avg'])."/day over the last {$main['days']} days{$excluded}.";

                $usable = collect($e['windows'])->filter(fn ($w) => $w['avg'] !== null);
                $parts = $usable->map(fn ($w) => "{$w['days']} days ".$this->num($w['avg']).'/day × '.round($w['weight'] * 100).'%')->values();
                // Only worth a sentence when the windows visibly disagree.
                if ($parts->count() > 1 && $usable->map(fn ($w) => $this->num($w['avg']))->unique()->count() > 1) {
                    $out[] = 'Blended rate '.$this->num($e['base_avg']).'/day ('.$parts->implode(', ').').';
                }
            }
        }

        // Seasonality
        $s = $e['seasonality'];
        if ($s['applied']) {
            $dir = $s['factor'] >= 1 ? 'rose' : 'fell';
            $out[] = "Last year, sales {$dir} ×{$this->num($s['factor'])} over the next {$s['horizon_days']} days, so we adjusted to ".$this->num($e['computed_avg']).'/day.';
        }

        // Bundles
        foreach ($e['bundles'] as $b) {
            if ($b['units_per_day'] > 0) {
                $out[] = 'Includes '.$this->num($b['units_per_day'])."/day sold inside \"{$b['name']}\" ({$b['quantity_per_bundle']} per bundle).";
            }
        }

        // Reorder
        $lead = $e['lead_time'];
        $source = sprintf(self::LEAD_SOURCES[$lead['source']] ?? $lead['source'], $lead['supplier'] ?? '');
        if ($e['avg_daily_sales'] > 0) {
            $out[] = "Lead time {$lead['days']} days ({$source}) + {$e['safety']['days']} safety days → reorder point {$e['reorder']['point']} units.";

            $stock = $e['stock']['current'];
            if ($stock <= 0) {
                $out[] = "Out of stock now → order {$e['reorder']['suggested_qty']} units today.";
            } else {
                $out[] = "{$stock} in stock runs out around ".$this->date($e['stockout_date'])
                    .' → order '.$e['reorder']['suggested_qty'].' units by '.$this->date($e['reorder']['date']).'.';
            }
        }

        // Confidence
        $c = $e['confidence'];
        $why = collect($c['reasons'])->map(fn ($r) => match ($r['code']) {
            'little_history' => "only {$r['in_stock_days']} days of in-stock history",
            'few_sales' => $r['units'] > 0 ? 'few sales ('.$this->num($r['units']).' in 90 days)' : 'no sales in 90 days',
            'volatile' => 'sales vary a lot week to week',
            'average_overridden' => 'rate set manually',
            default => $r['code'],
        })->implode(', ');
        $out[] = 'Confidence: '.$c['level'].($why !== '' ? " ({$why})" : '').'.';

        return $out;
    }

    private function num(float|int|null $n): string
    {
        return $n === null ? '–' : rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.');
    }

    private function date(?string $date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('M j') : '–';
    }
}
