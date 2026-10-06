<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;

/**
 * Expected sales over the next 30, 60 and 90 days at the forecast's daily rate, day by day so
 * sales events count on their days, next to the stock there is to cover them. Pure.
 */
final class DemandProjection
{
    public const HORIZONS = [30, 60, 90];

    public function __construct(private readonly ForecastCalculator $calculator) {}

    /**
     * @param  array<int, array{name: string, from: string, to: string, multiplier: float}>  $events
     * @return array<int, array{days: int, until: string, units: int, shortfall: int, events: bool}>
     */
    public function project(string $today, float $avgDailySales, array $events, int $stock, int $incoming): array
    {
        $start = CarbonImmutable::parse($today);
        $available = max(0, $stock) + max(0, $incoming);
        $out = [];
        $units = 0.0;
        $withEvents = false;
        $day = 0;
        foreach (self::HORIZONS as $days) {
            for (; $day < $days; $day++) {
                $m = $events === [] ? 1.0 : $this->calculator->multiplier($events, $start->addDays($day)->toDateString());
                $withEvents = $withEvents || $m !== 1.0;
                $units += max(0.0, $avgDailySales) * $m;
            }
            $rounded = (int) round($units);
            $out[] = [
                'days' => $days,
                'until' => $start->addDays($days - 1)->toDateString(),
                'units' => $rounded,
                // What stock on hand and on the way do not cover.
                'shortfall' => max(0, $rounded - $available),
                'events' => $withEvents,
            ];
        }

        return $out;
    }
}
