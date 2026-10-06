<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Models\Forecast;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Forecast> */
class ForecastFactory extends Factory
{
    protected $model = Forecast::class;

    public function definition(): array
    {
        $avg = fake()->randomFloat(2, 0.5, 10);
        $stock = fake()->numberBetween(0, 300);
        $cover = $stock / $avg;

        return [
            'variant_id' => Variant::factory(),
            'shop_id' => fn (array $a) => Variant::find($a['variant_id'])->shop_id,
            'location_id' => null,
            'current_stock' => $stock,
            'avg_daily_sales' => $avg,
            'days_of_cover' => round($cover, 1),
            'stockout_date' => now()->addDays((int) $cover)->toDateString(),
            'reorder_date' => now()->addDays(max(0, (int) $cover - 21))->toDateString(),
            'reorder_point' => (int) ceil($avg * 21),
            'suggested_qty' => (int) ceil($avg * 30),
            'confidence' => Confidence::Medium,
            'explanation' => [],
            'computed_at' => now(),
        ];
    }
}
