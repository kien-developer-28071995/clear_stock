<?php

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Forecast;
use App\Models\Location;
use App\Models\Shop;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'starter']);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];

    // Mug needs reordering today; Vase is healthy.
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4, attrs: ['unit_cost' => 3]);
    $this->vase = product($this->shop, $this->location, 'Vase', stock: 200, perDay: 4);
    app(ForecastService::class)->runForShop($this->shop);
});

it('stops suggesting orders for a discontinued product and shows it apart', function () {
    $this->getJson('/api/dashboard', $this->auth)->assertJsonPath('data.actions.order_today.0.name', 'Mug');

    $this->putJson("/api/variants/{$this->mug->id}/settings", ['discontinued' => true], $this->auth)
        ->assertOk()->assertJsonPath('data.discontinued', true);

    $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertOk()
        ->assertJsonPath('data.status', 'discontinued')
        ->assertJsonPath('data.suggested_qty', 0)
        ->assertJsonPath('data.reorder_date', null)
        ->assertJsonPath('data.stockout_date', '2026-09-22')
        ->assertJsonPath('data.settings.discontinued', true)
        ->assertJsonPath('data.explanation_lines.1.code', 'discontinued_sells_through');

    $this->getJson('/api/dashboard', $this->auth)->assertOk()
        ->assertJsonPath('data.actions.order_today', [])
        ->assertJsonPath('data.counts.discontinued', 1)
        ->assertJsonPath('data.counts.reorder_now', 0)
        ->assertJsonPath('data.discontinued', ['count' => 1, 'units' => 10, 'value' => 30, 'missing_cost' => 0]);

    $this->getJson('/api/forecasts?status=discontinued', $this->auth)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mug');
    $this->getJson('/api/forecasts?status=healthy', $this->auth)->assertJsonCount(1, 'data');

    // Starter has no product limit: no full recompute needed.
    Queue::assertNotPushed(RecomputeForecasts::class);
});

it('does not count discontinued products toward the Free plan limit', function () {
    config(['billing.plans.free.limits.max_skus' => 1]);
    $this->shop->update(['plan' => 'free']);
    Forecast::query()->delete();

    $this->putJson('/api/variants/settings', ['variant_ids' => [$this->mug->id], 'discontinued' => true], $this->auth)
        ->assertOk()->assertJsonPath('data.updated', 1);
    Queue::assertPushed(RecomputeForecasts::class, fn ($job) => $job->variantIds === null);

    $stats = app(ForecastService::class)->runForShop($this->shop->fresh());

    expect($stats['not_forecasted'])->toBe(0)
        ->and(Forecast::whereNull('location_id')->pluck('variant_id')->sort()->values()->all())->toBe([$this->mug->id, $this->vase->id]);
});
