<?php

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\DailySale;
use App\Models\Location;
use App\Models\Shop;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];

    // Mug: 4/day, then 8/day for the last 14 days. Cup: 4/day throughout.
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 500, perDay: 4);
    DailySale::query()->where('variant_id', $this->mug->id)->where('date', '>=', '2026-09-06')->update(['units_sold' => 8]);
    $this->cup = product($this->shop, $this->location, 'Cup', stock: 500, perDay: 4);
    app(ForecastService::class)->runForShop($this->shop);
});

it('uses the store profile, and a product\'s own first', function () {
    // balanced: 7d 8 x .2 + 30d 5.87 x .5 + 90d 4.62 x .3
    expect((float) $this->mug->forecast->avg_daily_sales)->toBe(5.92);

    $this->putJson('/api/settings', ['forecast_profile' => 'recent'], $this->auth)->assertOk()->assertJsonPath('data.forecast_profile', 'recent');
    Queue::assertPushed(RecomputeForecasts::class);
    app(ForecastService::class)->runForShop($this->shop->fresh());
    expect((float) $this->mug->forecast()->first()->avg_daily_sales)->toBe(6.81); // 8 x .5 + 5.87 x .4 + 4.62 x .1

    $this->putJson("/api/variants/{$this->mug->id}/settings", ['forecast_profile' => 'steady'], $this->auth)->assertOk()->assertJsonPath('data.forecast_profile', 'steady');
    $detail = $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertOk()->json('data');
    expect($detail['explanation']['profile'])->toBe(['name' => 'steady', 'source' => 'variant'])
        ->and($detail['settings']['forecast_profile'])->toBe('steady')
        ->and($detail['defaults']['forecast_profile'])->toBe('recent')
        ->and(array_column($detail['explanation_lines'], 'code'))->toContain('profile_steady_product');

    $this->putJson('/api/settings', ['forecast_profile' => 'nope'], $this->auth)->assertStatus(422);
});

it('lists and filters products by trend', function () {
    $rows = collect($this->getJson('/api/forecasts?sort=name', $this->auth)->assertOk()->json('data'))->pluck('trend_percent', 'name');
    expect($rows->all())->toBe(['Cup' => 0, 'Mug' => 100]);

    expect($this->getJson('/api/forecasts?trend=up', $this->auth)->json('data.*.name'))->toBe(['Mug'])
        ->and($this->getJson('/api/forecasts?trend=down', $this->auth)->json('data'))->toBe([]);
    expect($this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->json('data.trend'))->toMatchArray(['direction' => 'up', 'percent' => 100]);
});
