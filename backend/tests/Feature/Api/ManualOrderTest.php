<?php

use App\Mail\SupplierOrderMail;
use App\Models\AlertSetting;
use App\Models\Forecast;
use App\Models\Location;
use App\Models\ManualOrder;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Mail::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'free',
        'default_lead_time_days' => 14, 'default_safety_days' => 7, 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    // 4/day, 10 in stock: order 194 today (up to 204).
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);
    app(ForecastService::class)->runForShop($this->shop);
});

$forecast = fn () => Forecast::where('variant_id', test()->mug->id)->whereNull('location_id')->first();

it('counts products marked as ordered as on the way, and explains it', function () use ($forecast) {
    expect($forecast()->suggested_qty)->toBe(194);

    $this->postJson('/api/manual-orders', ['items' => [['variant_id' => $this->mug->id, 'quantity' => 194]], 'reference' => 'PO-7'], $this->auth)
        ->assertCreated()->assertJsonPath('data.recorded', 1);

    expect($forecast()->suggested_qty)->toBe(0)->and($forecast()->incoming_stock)->toBe(194);
    $order = ManualOrder::first();
    expect($order->expected_on->toDateString())->toBe('2026-10-04')   // today + 14 days lead time
        ->and($order->reference)->toBe('PO-7');

    $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertOk()
        ->assertJsonPath('data.explanation.stock.ordered.units', 194);
    $codes = collect($this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->json('data.explanation_lines'))->pluck('code');
    expect($codes)->toContain('ordered_manual')->not->toContain('incoming_stock');

    $this->getJson('/api/manual-orders', $this->auth)->assertOk()
        ->assertJsonPath('data.open.0.name', 'Mug')->assertJsonPath('data.open.0.state', 'open');
});

it('stops counting an order once received, cancelled or well past its date', function () use ($forecast) {
    $this->postJson('/api/manual-orders', ['items' => [['variant_id' => $this->mug->id, 'quantity' => 194]], 'expected_on' => '2026-09-25'], $this->auth)->assertCreated();
    $id = ManualOrder::value('id');

    // Two days late: still counted (grace days).
    $this->travelTo('2026-09-27 10:00:00');
    app(ForecastService::class)->runForShop($this->shop);
    expect($forecast()->incoming_stock)->toBe(194);
    $this->getJson('/api/manual-orders', $this->auth)->assertJsonPath('data.open.0.state', 'late');

    // Past the grace days: no longer counted, flagged for the merchant.
    $this->travelTo('2026-09-30 10:00:00');
    app(ForecastService::class)->runForShop($this->shop);
    expect($forecast()->incoming_stock)->toBe(0);
    $this->getJson('/api/manual-orders', $this->auth)->assertJsonPath('data.open.0.state', 'overdue');

    // Rescheduled: counted again; received: gone.
    $this->patchJson("/api/manual-orders/{$id}", ['expected_on' => '2026-10-05'], $this->auth)->assertOk()->assertJsonPath('data.state', 'open');
    expect($forecast()->incoming_stock)->toBe(194);
    $this->patchJson("/api/manual-orders/{$id}", ['status' => 'received'], $this->auth)->assertOk()->assertJsonPath('data.state', 'received');
    expect($forecast()->incoming_stock)->toBe(0);
    $this->getJson('/api/manual-orders', $this->auth)->assertJsonCount(0, 'data.open')->assertJsonPath('data.closed.0.state', 'received');
});

it('records what is emailed to a supplier as ordered', function () use ($forecast) {
    $this->shop->update(['plan' => 'starter']);
    AlertSetting::factory()->for($this->shop)->create(['email' => 'owner@demo.test']);
    $acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'email' => 'orders@acme.test', 'lead_time_days' => 10]);
    $this->mug->update(['supplier_id' => $acme->id]);
    app(ForecastService::class)->runForShop($this->shop->fresh());

    $this->postJson("/api/suppliers/{$acme->id}/email", ['items' => [['variant_id' => $this->mug->id, 'quantity' => 150]]], $this->auth)->assertSuccessful();

    Mail::assertQueued(SupplierOrderMail::class);
    $order = ManualOrder::first();
    expect($order->source)->toBe('supplier_email')->and($order->quantity)->toBe(150)
        ->and($order->supplier_id)->toBe($acme->id)
        ->and($order->expected_on->toDateString())->toBe('2026-09-30')   // supplier lead time 10 days
        ->and($forecast()->incoming_stock)->toBe(150);
});

it('ignores other shops products and empty orders', function () {
    $other = product(Shop::factory()->create(), $this->location, 'Other', stock: 1, perDay: 1);
    $this->postJson('/api/manual-orders', ['items' => [['variant_id' => $other->id, 'quantity' => 5]]], $this->auth)
        ->assertUnprocessable()->assertJsonPath('errors.items.0.code', 'no_items');
    expect(ManualOrder::count())->toBe(0);
});

it('records the same order once when the request arrives twice, and a later one again', function () {
    $body = ['items' => [['variant_id' => $this->mug->id, 'quantity' => 40]], 'reference' => 'PO-7'];

    $this->postJson('/api/manual-orders', $body, $this->auth)->assertCreated()->assertJsonPath('data.recorded', 1);
    $this->postJson('/api/manual-orders', $body, $this->auth)->assertCreated()->assertJsonPath('data.recorded', 1);   // double click / retry
    expect(ManualOrder::where('variant_id', $this->mug->id)->count())->toBe(1);

    // A different order straight away is a different order; the same one later is a new one.
    $this->postJson('/api/manual-orders', ['items' => [['variant_id' => $this->mug->id, 'quantity' => 41]], 'reference' => 'PO-7'], $this->auth)->assertCreated();
    $this->travel(30)->seconds();
    $this->postJson('/api/manual-orders', $body, $this->auth)->assertCreated();
    expect(ManualOrder::where('variant_id', $this->mug->id)->count())->toBe(3);

    // Ordered, cancelled at once (a mistake), ordered again: the second one counts.
    ManualOrder::query()->update(['status' => ManualOrder::CANCELLED]);
    $this->postJson('/api/manual-orders', $body, $this->auth)->assertCreated();
    expect(ManualOrder::where('variant_id', $this->mug->id)->where('status', ManualOrder::OPEN)->count())->toBe(1);
});
