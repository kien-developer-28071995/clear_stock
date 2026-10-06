<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Variant> */
class VariantFactory extends Factory
{
    protected $model = Variant::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'shopify_variant_id' => fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
            'shopify_product_id' => fake()->numberBetween(1_000_000_000, 9_999_999_999),
            'inventory_item_id' => fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
            'product_title' => ucfirst(fake()->words(2, true)),
            'title' => fake()->randomElement(['Default Title', 'Small', 'Medium', 'Large']),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'unit_cost' => fake()->randomFloat(2, 1, 80),
            'price' => fake()->randomFloat(2, 90, 200),
            'tracked' => true,
            'is_active' => true,
            'is_bundle' => false,
        ];
    }

    public function bundle(): static
    {
        return $this->state(fn () => ['is_bundle' => true]);
    }
}
