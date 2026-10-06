<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    protected $model = Shop::class;

    public function definition(): array
    {
        return [
            'domain' => fake()->unique()->slug(2).'.myshopify.com',
            'name' => fake()->company(),
            'access_token' => 'shpat_'.fake()->sha1(),
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'shprt_'.fake()->sha1(),
            'refresh_token_expires_at' => now()->addDays(90),
            'scopes' => config('shopify.scopes'),
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'installed_at' => now(),
        ];
    }

    public function uninstalled(): static
    {
        return $this->state(fn () => [
            'access_token' => null,
            'refresh_token' => null,
            'uninstalled_at' => now(),
        ]);
    }
}
