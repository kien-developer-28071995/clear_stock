<?php

namespace App\Support\Dev;

/**
 * DEV ONLY. Daily unit patterns used to create fake orders in a development
 * store, one scenario per variant, so every branch of the forecast engine
 * (steady, trend, spike, seasonality, stock-out, slow mover, volatile, dead
 * stock) can be seen with real Shopify data.
 */
final class FakeSalesScenarios
{
    /** Scenario keys, assigned to variants in this order (matched by title first, then round-robin). */
    public const SCENARIOS = [
        'Minimal' => 'steady_fast',
        'Hydrogen' => 'steady',
        'Out of Stock' => 'sold_out',
        'Videographer' => 'trend_up',
        'Liquid' => 'recent_spike',
        'Oxygen' => 'seasonal',
        'Multi-location' => 'volatile',
        'Compare at Price' => 'slow',
        'Hidden' => 'dead_stock',
    ];

    public const FALLBACK = ['low', 'low_alt'];

    public const DESCRIPTIONS = [
        'steady_fast' => '4/day every day',
        'steady' => '2/day every day',
        'sold_out' => '2/day, nothing in the last 10 days (sold out)',
        'trend_up' => 'rising from ~0.5/day to 5/day',
        'recent_spike' => '1/day, 5/day in the last 7 days',
        'seasonal' => '1/day; last year 1.5/day then 3/day from this date (x2)',
        'volatile' => 'one week 6/day, then 3 weeks with none',
        'slow' => '1 every 4 days',
        'dead_stock' => 'no sales',
        'low' => '1 every 2 days',
        'low_alt' => '1 every 3 days',
    ];

    /** Units sold $daysAgo days ago (1 = yesterday) for a scenario. Deterministic. */
    public static function units(string $scenario, int $daysAgo): int
    {
        return match ($scenario) {
            'steady_fast' => 4,
            'steady' => 2,
            'sold_out' => $daysAgo <= 10 ? 0 : 2,
            'trend_up' => (int) round(max(0.5, 5 - $daysAgo * 0.05) + (($daysAgo % 2) ? 0.3 : -0.3)),
            'recent_spike' => $daysAgo <= 7 ? 5 : 1,
            'seasonal' => match (true) {
                $daysAgo >= 336 && $daysAgo <= 365 => 3,   // last year: the 30 days starting "one year ago today"
                $daysAgo > 365 && $daysAgo <= 395 => ($daysAgo % 2) + 1, // the 30 days before: ~1.5/day
                default => 1,
            },
            'volatile' => intdiv($daysAgo - 1, 7) % 4 === 0 ? 6 : 0,
            'slow' => $daysAgo % 4 === 0 ? 1 : 0,
            'dead_stock' => 0,
            'low' => $daysAgo % 2 === 0 ? 1 : 0,
            'low_alt' => $daysAgo % 3 === 0 ? 1 : 0,
            default => 0,
        };
    }
}
