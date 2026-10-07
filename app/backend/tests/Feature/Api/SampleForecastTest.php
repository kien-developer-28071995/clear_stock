<?php

use App\Models\Forecast;
use App\Models\Shop;
use App\Models\Variant;

beforeEach(function () {
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'access_token_expires_at' => now()->addYear()]);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

it('forecasts a made-up catalog with the real calculator and stores nothing', function () {
    $rows = collect($this->getJson('/api/sample-forecasts', $this->auth)->assertOk()->json('data'))->keyBy('key');

    expect($rows)->toHaveCount(5)
        ->and($rows['linen_shirt']['status'])->toBe('reorder_now')
        ->and($rows['linen_shirt']['suggested_qty'])->toBeGreaterThan(0)
        ->and($rows['linen_shirt']['reorder_date'])->toBe('2026-09-20')
        ->and($rows['canvas_tote']['status'])->toBe('out_of_stock')
        ->and($rows['ceramic_mug']['status'])->toBe('healthy')
        ->and($rows['scented_candle']['status'])->toBe('slow')
        // Whole cases of 12, at least 24.
        ->and($rows['wool_beanie']['suggested_qty'] % 12)->toBe(0);

    foreach ($rows as $row) {
        expect($row['explanation_lines'])->not->toBeEmpty()
            ->and($row['explanation_lines'][0])->toHaveKeys(['code', 'params']);
    }
    expect(Forecast::count())->toBe(0)->and(Variant::count())->toBe(0);
});

it('uses the lead time and safety days the shop chose', function () {
    $before = collect($this->getJson('/api/sample-forecasts', $this->auth)->json('data'))->keyBy('key');
    $this->shop->update(['default_lead_time_days' => 45]);
    $after = collect($this->getJson('/api/sample-forecasts', $this->auth)->json('data'))->keyBy('key');

    expect($after['ceramic_mug']['suggested_qty'])->toBeGreaterThan($before['ceramic_mug']['suggested_qty'])
        ->and($after['ceramic_mug']['status'])->toBe('reorder_now');
});

it('does not exist when switched off', function () {
    config(['features.sample_data' => false]);

    $this->getJson('/api/sample-forecasts', $this->auth)->assertNotFound()->assertJsonPath('code', 'feature_disabled');
});
