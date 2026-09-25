<?php

namespace Database\Factories;

use App\Models\BundleComponent;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BundleComponent> */
class BundleComponentFactory extends Factory
{
    protected $model = BundleComponent::class;

    public function definition(): array
    {
        $bundle = Variant::factory()->bundle();

        return [
            'bundle_variant_id' => $bundle,
            'component_variant_id' => fn (array $a) => Variant::factory()->create(['shop_id' => Variant::find($a['bundle_variant_id'])->shop_id])->id,
            'shop_id' => fn (array $a) => Variant::find($a['bundle_variant_id'])->shop_id,
            'quantity' => fake()->numberBetween(1, 3),
        ];
    }
}
