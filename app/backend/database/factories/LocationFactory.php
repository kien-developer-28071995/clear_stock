<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Location> */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'shopify_location_id' => fake()->unique()->numberBetween(10_000_000, 99_999_999),
            'name' => fake()->city().' warehouse',
            'is_active' => true,
        ];
    }
}
