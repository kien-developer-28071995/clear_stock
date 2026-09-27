<?php

namespace Database\Factories;

use App\Models\ManualOrder;
use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ManualOrder> */
class ManualOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'variant_id' => Variant::factory(),
            'quantity' => 50,
            'ordered_on' => '2026-09-20',
            'expected_on' => '2026-10-04',
            'source' => ManualOrder::SOURCE_MANUAL,
            'status' => ManualOrder::OPEN,
        ];
    }
}
