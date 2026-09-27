<?php

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Forecast;
use App\Models\Location;
use App\Models\SalesEvent;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'free',
        'default_lead_time_days' => 14, 'default_safety_days' => 7]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    $this->acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'lead_time_days' => null]);
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 200, perDay: 4, attrs: ['supplier_id' => $this->acme->id]);
    $this->cup = product($this->shop, $this->location, 'Cup', stock: 200, perDay: 4, attrs: ['shopify_variant_id' => 555]);
});

function eventBody(array $overrides = []): array
{
    return $overrides + ['name' => 'Promo', 'starts_on' => '2026-09-25', 'ends_on' => '2026-09-29', 'multiplier' => 2, 'applies_to' => 'all'];
}

it('creates, lists, updates and deletes events, recomputing the forecasts each time', function () {
    $id = $this->postJson('/api/sales-events', eventBody(), $this->auth)->assertCreated()
        ->assertJsonPath('data.multiplier', 2)->assertJsonPath('data.applies_to', 'all')->json('data.id');
    Queue::assertPushed(RecomputeForecasts::class, fn ($job) => $job->variantIds === null);

    $this->putJson("/api/sales-events/{$id}", eventBody(['name' => 'Black Friday', 'applies_to' => 'supplier', 'supplier_id' => $this->acme->id]), $this->auth)
        ->assertOk()->assertJsonPath('data.supplier', 'Acme');
    $this->getJson('/api/sales-events', $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Black Friday');

    $this->deleteJson("/api/sales-events/{$id}", [], $this->auth)->assertNoContent();
    expect(SalesEvent::count())->toBe(0);
});

it('validates dates, multiplier and scope', function () {
    $this->postJson('/api/sales-events', eventBody(['ends_on' => '2026-09-01']), $this->auth)->assertUnprocessable()->assertJsonPath('errors.ends_on.0.code', 'after_or_equal');
    $this->postJson('/api/sales-events', eventBody(['ends_on' => '2027-03-01']), $this->auth)->assertUnprocessable()->assertJsonPath('errors.ends_on.0.code', 'event_too_long');
    $this->postJson('/api/sales-events', eventBody(['multiplier' => 1]), $this->auth)->assertUnprocessable();
    $this->postJson('/api/sales-events', eventBody(['applies_to' => 'supplier']), $this->auth)->assertUnprocessable()->assertJsonPath('errors.supplier_id.0.code', 'required_if');
});

it('applies an event to the whole shop, one supplier or picked products', function () {
    $point = fn ($v) => Forecast::where('variant_id', $v->id)->whereNull('location_id')->value('reorder_point');

    $this->postJson('/api/sales-events', eventBody(['applies_to' => 'supplier', 'supplier_id' => $this->acme->id]), $this->auth)->assertCreated();
    app(ForecastService::class)->runForShop($this->shop);
    expect($point($this->mug))->toBe(104)->and($point($this->cup))->toBe(84);

    SalesEvent::query()->delete();
    $this->postJson('/api/sales-events', eventBody(['applies_to' => 'products', 'variant_ids' => ['gid://shopify/ProductVariant/555']]), $this->auth)
        ->assertCreated()->assertJsonPath('data.variant_ids', [$this->cup->id]);
    app(ForecastService::class)->runForShop($this->shop);
    expect($point($this->mug))->toBe(84)->and($point($this->cup))->toBe(104);

    $this->getJson("/api/forecasts/{$this->cup->id}", $this->auth)->assertOk()
        ->assertJsonPath('data.explanation.events.upcoming.0.name', 'Promo');
});

it('is ignored when switched off app-wide', function () {
    SalesEvent::factory()->for($this->shop)->create(['starts_on' => '2026-09-25', 'ends_on' => '2026-09-29']);
    config(['features.sales_events' => false]);
    app(ForecastService::class)->runForShop($this->shop);

    expect(Forecast::where('variant_id', $this->mug->id)->value('reorder_point'))->toBe(84);
    $this->getJson('/api/sales-events', $this->auth)->assertNotFound()->assertJsonPath('code', 'feature_disabled');
});
