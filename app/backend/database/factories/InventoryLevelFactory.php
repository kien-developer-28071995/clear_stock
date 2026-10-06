<?php

namespace Database\Factories;

use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryLevel> */
class InventoryLevelFactory extends Factory
{
    protected $model = InventoryLevel::class;

    public function definition(): array
    {
        return [
            'variant_id' => Variant::factory(),
            'shop_id' => fn (array $a) => Variant::find($a['variant_id'])->shop_id,
            'location_id' => fn (array $a) => Location::factory()->create(['shop_id' => $a['shop_id']])->id,
            'available' => fake()->numberBetween(0, 200),
        ];
    }
}
