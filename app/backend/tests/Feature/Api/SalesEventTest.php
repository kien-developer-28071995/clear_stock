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

it('applies a yearly season on the same dates every year, past and upcoming', function () {
    // Entered last year for Sep 25 – 29: this year's occurrence is ahead, last year's is in the history.
    $this->postJson('/api/sales-events', eventBody(['name' => 'Autumn peak', 'starts_on' => '2025-09-25', 'ends_on' => '2025-09-29', 'repeats_yearly' => true]), $this->auth)
        ->assertCreated()->assertJsonPath('data.repeats_yearly', true);
    app(ForecastService::class)->runForShop($this->shop);

    $events = Forecast::where('variant_id', $this->mug->id)->first()->explanation['events'];
    expect(array_column($events['upcoming'], 'from'))->toBe(['2026-09-25'])
        ->and($events['upcoming'][0])->toMatchArray(['name' => 'Autumn peak', 'to' => '2026-09-29', 'multiplier' => 2.0, 'units_order' => 20.0]);

    // Without the yearly repeat the old event is over: nothing ahead.
    SalesEvent::query()->update(['repeats_yearly' => false]);
    app(ForecastService::class)->runForShop($this->shop);
    expect(Forecast::where('variant_id', $this->mug->id)->first()->explanation['events']['upcoming'])->toBe([]);
});

it('expands a season into the occurrences inside a window', function () {
    $season = new SalesEvent(['name' => 'Holidays', 'starts_on' => '2024-12-20', 'ends_on' => '2025-01-05', 'multiplier' => 3, 'repeats_yearly' => true]);

    expect(array_column($season->occurrences('2025-08-01', '2027-10-01'), 'from'))->toBe(['2025-12-20', '2026-12-20'])
        ->and(array_column($season->occurrences('2026-01-01', '2026-01-03'), 'to'))->toBe(['2026-01-05'])      // spans New Year
        ->and($season->fill(['repeats_yearly' => false])->occurrences('2026-01-01', '2026-12-31'))->toHaveCount(1);
});
