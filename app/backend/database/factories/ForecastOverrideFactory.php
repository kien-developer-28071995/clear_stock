<?php

namespace Database\Factories;

use App\Enums\OverrideField;
use App\Models\ForecastOverride;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ForecastOverride> */
class ForecastOverrideFactory extends Factory
{
    protected $model = ForecastOverride::class;

    public function definition(): array
    {
        return [
            'variant_id' => Variant::factory(),
            'shop_id' => fn (array $a) => Variant::find($a['variant_id'])->shop_id,
            'field' => OverrideField::AvgDailySales,
            'value' => 5,
            'note' => 'Promo next month',
        ];
    }
}
