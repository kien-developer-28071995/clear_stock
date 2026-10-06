<?php

use App\Models\ChangeLog;
use App\Models\FeatureEvent;
use App\Models\Location;
use App\Models\SalesEvent;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    // The access token outlives the jumps in time these tests make.
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'starter', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];

    // Mug and Cup need reordering today; Vase is healthy.
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4, attrs: ['unit_cost' => 3]);
    $this->cup = product($this->shop, $this->location, 'Cup', stock: 10, perDay: 4);
    $this->vase = product($this->shop, $this->location, 'Vase', stock: 200, perDay: 4);
    app(ForecastService::class)->runForShop($this->shop);
});

// --- "Not now" ---

it('keeps a snoozed product out of the reorder lists until the day it comes back', function () {
    // A session token lives a minute: a new one after every jump in time.
    $names = fn () => collect($this->getJson('/api/dashboard', $this->auth = ['Authorization' => 'Bearer '.sessionToken()])->assertOk()->json('data.actions'))->flatten(1)->pluck('name')->sort()->values()->all();
    expect($names())->toBe(['Cup', 'Mug']);

    $this->postJson('/api/snooze', ['variant_ids' => [$this->mug->id], 'days' => 7], $this->auth)
        ->assertOk()->assertJsonPath('data', ['updated' => 1, 'until' => '2026-09-27']);

    expect($names())->toBe(['Cup']);
    $dashboard = $this->getJson('/api/dashboard', $this->auth)->assertOk();
    // The forecast itself does not change: still counted as needing a reorder.
    $dashboard->assertJsonPath('data.counts.reorder_now', 2)
        ->assertJsonPath('data.snoozed', [['variant_id' => $this->mug->id, 'name' => 'Mug', 'until' => '2026-09-27']]);
    $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)
        ->assertJsonPath('data.status', 'reorder_now')->assertJsonPath('data.snoozed_until', '2026-09-27');

    // The purchase order of "everything due" leaves it out; asked for by name it is still there.
    $csv = fn (string $query) => $this->get('/api/purchase-orders/export'.$query, $this->auth)->assertOk()->streamedContent();
    expect($csv(''))->toContain('Cup')->not->toContain('Mug')
        ->and($csv('?variant_ids='.$this->mug->id))->toContain('Mug');

    // The day before it is still away; on the day itself it is back.
    $this->travelTo('2026-09-26 10:00:00');
    expect($names())->toBe(['Cup']);
    $this->travelTo('2026-09-27 10:00:00');
    expect($names())->toBe(['Cup', 'Mug']);
    $this->getJson('/api/dashboard', $this->auth)->assertJsonPath('data.snoozed', []);
    $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertJsonPath('data.snoozed_until', null);
});

it('brings a snoozed product back at once', function () {
    $this->postJson('/api/snooze', ['variant_ids' => [$this->mug->id, $this->cup->id], 'days' => 30], $this->auth)->assertJsonPath('data.updated', 2);
    $this->getJson('/api/dashboard', $this->auth)->assertJsonPath('data.actions.order_today', [])->assertJsonCount(2, 'data.snoozed');

    $this->postJson('/api/snooze', ['variant_ids' => [$this->mug->id], 'days' => null], $this->auth)->assertJsonPath('data', ['updated' => 1, 'until' => null]);
    $this->getJson('/api/dashboard', $this->auth)->assertJsonPath('data.actions.order_today.0.name', 'Mug')->assertJsonCount(1, 'data.snoozed');
});

it('refuses a snooze that is not a number of days, and never touches another shop', function () {
    $other = Shop::factory()->create(['domain' => 'other.myshopify.com']);
    $theirs = product($other, Location::factory()->for($other)->create(), 'Theirs', stock: 1, perDay: 1);

    $this->postJson('/api/snooze', ['variant_ids' => [$this->mug->id], 'days' => 0], $this->auth)->assertStatus(422);
    $this->postJson('/api/snooze', ['variant_ids' => [$this->mug->id], 'days' => 9999], $this->auth)->assertStatus(422);
    $this->postJson('/api/snooze', ['variant_ids' => [], 'days' => 7], $this->auth)->assertStatus(422);
    $this->postJson('/api/snooze', ['variant_ids' => [$theirs->id], 'days' => 7], $this->auth)->assertOk()->assertJsonPath('data.updated', 0);

    expect($theirs->fresh()->snoozed_until)->toBeNull();
});

it('snoozes nothing when the feature is switched off', function () {
    $this->mug->update(['snoozed_until' => '2026-10-20']);
    config(['features.snooze' => false]);

    $this->postJson('/api/snooze', ['variant_ids' => [$this->cup->id], 'days' => 7], $this->auth)->assertStatus(404)->assertJsonPath('code', 'feature_disabled');
    $this->getJson('/api/dashboard', $this->auth)->assertJsonCount(2, 'data.actions.order_today')->assertJsonPath('data.snoozed', []);
    $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertJsonPath('data.snoozed_until', null);
});

// --- Demand over the next 30/60/90 days ---

it('projects sales over 30, 60 and 90 days next to the stock there is', function () {
    $this->getJson("/api/forecasts/{$this->vase->id}", $this->auth)->assertOk()->assertJsonPath('data.projection', [
        ['days' => 30, 'until' => '2026-10-19', 'units' => 120, 'shortfall' => 0, 'events' => false],
        ['days' => 60, 'until' => '2026-11-18', 'units' => 240, 'shortfall' => 40, 'events' => false],
        ['days' => 90, 'until' => '2026-12-18', 'units' => 360, 'shortfall' => 160, 'events' => false],
    ]);
});

it('counts a sales event on its days in the projection', function () {
    // Double sales for 10 days inside the second month: 10 extra days' worth.
    SalesEvent::query()->create(['shop_id' => $this->shop->id, 'name' => 'Sale', 'starts_on' => '2026-10-25', 'ends_on' => '2026-11-03', 'multiplier' => 2, 'scope' => 'all']);

    $projection = $this->getJson("/api/forecasts/{$this->vase->id}", $this->auth)->assertOk()->json('data.projection');

    expect(array_column($projection, 'units'))->toBe([120, 280, 400])
        ->and(array_column($projection, 'events'))->toBe([false, true, true]);
});

it('shows no projection for a product that does not sell, a discontinued one, or with the feature off', function () {
    $idle = product($this->shop, $this->location, 'Idle', stock: 5, perDay: 0);
    app(ForecastService::class)->runForShop($this->shop);
    $this->getJson("/api/forecasts/{$idle->id}", $this->auth)->assertJsonPath('data.projection', null);

    $this->putJson("/api/variants/{$this->vase->id}/settings", ['discontinued' => true], $this->auth)->assertOk();
    $this->getJson("/api/forecasts/{$this->vase->id}", $this->auth)->assertJsonPath('data.projection', null);

    config(['features.demand_projection' => false]);
    $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertJsonPath('data.projection', null);
});

// --- Change log ---

it('logs what changed on a product, old value next to new, and only real changes', function () {
    $supplier = Supplier::factory()->for($this->shop)->create(['name' => 'Acme']);

    $this->putJson("/api/variants/{$this->mug->id}/settings", ['lead_time_override' => 21, 'supplier_id' => $supplier->id, 'alerts_muted' => false], $this->auth)->assertOk();
    $this->putJson("/api/variants/{$this->mug->id}/settings", ['lead_time_override' => 21, 'min_stock' => 5], $this->auth)->assertOk();
    $this->putJson("/api/forecasts/{$this->mug->id}/overrides", ['avg_daily_sales' => ['value' => 6.5]], $this->auth)->assertOk();
    $this->putJson("/api/forecasts/{$this->mug->id}/overrides", ['avg_daily_sales' => ['value' => null]], $this->auth)->assertOk();
    $this->postJson('/api/snooze', ['variant_ids' => [$this->mug->id], 'days' => 7], $this->auth)->assertOk();

    $changes = $this->getJson("/api/forecasts/{$this->mug->id}/changes", $this->auth)->assertOk()->json('data');

    // Newest first; the unchanged lead time and the "not muted" that was already so are not logged.
    expect(array_map(fn ($c) => [$c['field'], $c['old'], $c['new'], $c['source']], $changes))->toBe([
        ['snoozed_until', null, '2026-09-27', 'app'],
        ['override.avg_daily_sales', '6.5', null, 'app'],
        ['override.avg_daily_sales', null, '6.5', 'app'],
        ['min_stock', null, '5', 'app'],
        ['lead_time_override', null, '21', 'app'],
        ['supplier', null, 'Acme', 'app'],
    ])->and($changes[0]['at'])->toStartWith('2026-09-20T10:00:00');
});

it('logs bulk edits and cost changes per product', function () {
    $this->putJson('/api/variants/settings', ['variant_ids' => [$this->mug->id, $this->cup->id], 'safety_days' => 10], $this->auth)->assertOk();
    $this->putJson('/api/costs', ['items' => [['variant_id' => $this->mug->id, 'cost' => 4.5]]], $this->auth)->assertOk();

    $fields = fn (int $id) => array_map(fn ($c) => [$c['field'], $c['old'], $c['new'], $c['source']], $this->getJson("/api/forecasts/{$id}/changes", $this->auth)->json('data'));

    expect($fields($this->mug->id))->toBe([['cost_override', null, '4.5', 'app'], ['safety_days', null, '10', 'bulk']])
        ->and($fields($this->cup->id))->toBe([['safety_days', null, '10', 'bulk']])
        ->and($fields($this->vase->id))->toBe([]);
});

it('keeps the log of one shop away from another and prunes it after 180 days', function () {
    $other = Shop::factory()->create(['domain' => 'other.myshopify.com']);
    $theirs = product($other, Location::factory()->for($other)->create(), 'Theirs', stock: 1, perDay: 1);
    ChangeLog::query()->create(['shop_id' => $other->id, 'variant_id' => $theirs->id, 'field' => 'min_stock', 'new_value' => '9', 'source' => 'app']);
    $this->putJson("/api/variants/{$this->mug->id}/settings", ['min_stock' => 5], $this->auth)->assertOk();

    $this->getJson("/api/forecasts/{$theirs->id}/changes", $this->auth)->assertStatus(404);

    $this->travelTo('2027-03-20 10:00:00');
    $this->artisan('model:prune', ['--model' => [ChangeLog::class]])->assertSuccessful();
    expect(ChangeLog::query()->count())->toBe(0);
});

it('writes no log and closes the endpoint when the change log is switched off', function () {
    config(['features.change_log' => false]);
    $this->putJson("/api/variants/{$this->mug->id}/settings", ['min_stock' => 5], $this->auth)->assertOk();

    expect(ChangeLog::query()->count())->toBe(0);
    $this->getJson("/api/forecasts/{$this->mug->id}/changes", $this->auth)->assertStatus(404)->assertJsonPath('code', 'feature_disabled');
});

// --- Feature usage ---

it('counts successful uses of features that store nothing, per shop and day', function () {
    $this->getJson('/api/what-if?growth=20', $this->auth)->assertOk();
    $this->getJson('/api/what-if?growth=30', $this->auth)->assertOk();
    $this->getJson('/api/what-if?growth=abc', $this->auth)->assertStatus(422);   // refused: not a use
    $this->getJson('/api/accuracy', $this->auth)->assertOk();
    $this->travelTo('2026-09-21 10:00:00');
    $this->getJson('/api/what-if?growth=20', ['Authorization' => 'Bearer '.sessionToken()])->assertOk();

    $rows = DB::table('feature_events')->orderBy('day')->orderBy('feature')->get(['shop_id', 'feature', 'day', 'count'])
        ->map(fn ($r) => [(int) $r->shop_id, $r->feature, substr((string) $r->day, 0, 10), (int) $r->count])->all();

    expect($rows)->toBe([
        [$this->shop->id, 'accuracy', '2026-09-20', 1],
        [$this->shop->id, 'what_if', '2026-09-20', 2],
        [$this->shop->id, 'what_if', '2026-09-21', 1],
    ]);
});

it('does not count a feature the plan refuses, and prunes old counts', function () {
    $this->shop->update(['plan' => 'free']);
    $this->getJson('/api/purchase-plan', $this->auth)->assertStatus(402);
    expect(DB::table('feature_events')->count())->toBe(0);

    DB::table('feature_events')->insert(['shop_id' => $this->shop->id, 'feature' => 'what_if', 'day' => '2025-01-01', 'count' => 3]);
    $this->artisan('model:prune', ['--model' => [FeatureEvent::class]])->assertSuccessful();
    expect(DB::table('feature_events')->count())->toBe(0);
});
