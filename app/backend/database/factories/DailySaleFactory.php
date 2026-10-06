<?php

namespace Database\Factories;

use App\Models\DailySale;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailySale> */
class DailySaleFactory extends Factory
{
    protected $model = DailySale::class;

    private static int $dayOffset = 0;

    public function definition(): array
    {
        return [
            'variant_id' => Variant::factory(),
            'shop_id' => fn (array $a) => Variant::find($a['variant_id'])->shop_id,
            // Consecutive past days, so several rows for one variant never collide on (variant_id, date).
            'date' => now()->subDays(++self::$dayOffset)->toDateString(),
            'units_sold' => fake()->numberBetween(0, 10),
            'units_returned' => 0,
            'end_of_day_stock' => fake()->numberBetween(1, 100),
            'was_in_stock' => true,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['units_sold' => 0, 'end_of_day_stock' => 0, 'was_in_stock' => false]);
    }
}
