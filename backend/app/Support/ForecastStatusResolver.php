<?php

namespace App\Support;

use App\Enums\ForecastStatus;
use App\Models\Forecast;

/** Same rules as the SQL filters in EloquentForecastQueryRepository, for a single row. */
final class ForecastStatusResolver
{
    public static function for(Forecast $f, string $today): ForecastStatus
    {
        $avg = (float) $f->avg_daily_sales;
        $cover = $f->days_of_cover !== null ? (float) $f->days_of_cover : null;

        return match (true) {
            $f->current_stock <= 0 && $avg > 0 => ForecastStatus::OutOfStock,
            $f->reorder_date !== null && $f->reorder_date->toDateString() <= $today => ForecastStatus::ReorderNow,
            $f->current_stock > 0 && ($avg == 0 || ($cover !== null && $cover > config('forecast.slow_mover_days'))) => ForecastStatus::Slow,
            $f->current_stock > 0 && $avg > 0 && $f->target_stock > 0
                && $f->excess_units > $f->target_stock * (float) config('forecast.overstock_ratio') => ForecastStatus::Overstock,
            default => ForecastStatus::Healthy,
        };
    }
}
