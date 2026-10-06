<?php

namespace Database\Factories;

use App\Enums\AlertFrequency;
use App\Models\AlertSetting;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertSetting> */
class AlertSettingFactory extends Factory
{
    protected $model = AlertSetting::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'email' => fake()->safeEmail(),
            'enabled' => true,
            'frequency' => AlertFrequency::Daily,
            'weekly_day' => 1,
        ];
    }
}
