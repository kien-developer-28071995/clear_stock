<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'name' => fake()->unique()->company(),
            'email' => fake()->companyEmail(),
            'lead_time_days' => fake()->randomElement([null, 7, 14, 21, 30]),
        ];
    }
}
