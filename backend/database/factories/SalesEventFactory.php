<?php

namespace Database\Factories;

use App\Models\SalesEvent;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesEvent> */
class SalesEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'name' => 'Black Friday',
            'starts_on' => '2026-11-27',
            'ends_on' => '2026-11-30',
            'multiplier' => 2,
            'applies_to' => SalesEvent::ALL,
        ];
    }
}
