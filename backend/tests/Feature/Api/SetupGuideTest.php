<?php

use App\Models\AlertSetting;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'last_synced_at' => null, 'onboarded_at' => null, 'access_token_expires_at' => now()->addYear()]);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

function steps(array $data): array
{
    return collect($data['steps'])->mapWithKeys(fn ($s) => [$s['key'] => $s['done'] ? 'done' : ($s['skipped'] ? 'skipped' : 'todo')])->all();
}

it('starts with every step to do', function () {
    $data = $this->getJson('/api/setup-guide', $this->auth)->assertOk()->json('data');

    expect(steps($data))->toBe(['import_data' => 'todo', 'lead_time' => 'todo', 'review_forecast' => 'todo', 'suppliers' => 'todo', 'alerts' => 'todo'])
        ->and($data['completed'])->toBe(0)->and($data['total'])->toBe(5)->and($data['dismissed'])->toBeFalse();
});

it('completes steps from real data', function () {
    $this->shop->update(['last_synced_at' => now(), 'onboarded_at' => now(), 'plan' => 'starter']);
    Supplier::factory()->for($this->shop)->create();
    AlertSetting::factory()->for($this->shop)->create(['enabled' => true, 'email' => 'a@b.test']);
    $mug = product($this->shop, Location::factory()->for($this->shop)->create(), 'Mug', stock: 10, perDay: 4);
    app(ForecastService::class)->runForShop($this->shop);

    $data = $this->getJson('/api/setup-guide', $this->auth)->json('data');

    expect(steps($data))->toBe(['import_data' => 'done', 'lead_time' => 'done', 'review_forecast' => 'todo', 'suppliers' => 'done', 'alerts' => 'done'])
        ->and($data['context']['example_variant'])->toBe(['id' => $mug->id, 'name' => 'Mug']);
});

it('does not count alerts as done on a plan without alerts', function () {
    AlertSetting::factory()->for($this->shop)->create(['enabled' => true, 'email' => 'a@b.test']);

    $data = $this->getJson('/api/setup-guide', $this->auth)->json('data');

    expect(steps($data)['alerts'])->toBe('todo')->and($data['context']['alerts_available'])->toBeFalse();
});

it('records viewing a forecast once', function () {
    $this->postJson('/api/setup-guide/events', ['event' => 'viewed_forecast'], $this->auth)->assertOk();
    $first = $this->shop->fresh()->setup_guide['events']['viewed_forecast'];
    $this->travel(1)->day();
    $data = $this->postJson('/api/setup-guide/events', ['event' => 'viewed_forecast'], $this->auth)->json('data');

    expect(steps($data)['review_forecast'])->toBe('done')
        ->and($this->shop->fresh()->setup_guide['events']['viewed_forecast'])->toBe($first);
    $this->postJson('/api/setup-guide/events', ['event' => 'hacked'], $this->auth)->assertUnprocessable();
});

it('lets merchants skip optional steps only', function () {
    $data = $this->postJson('/api/setup-guide/skip', ['step' => 'suppliers'], $this->auth)->assertOk()->json('data');

    expect(steps($data)['suppliers'])->toBe('skipped')->and($data['completed'])->toBe(1);
    $this->postJson('/api/setup-guide/skip', ['step' => 'import_data'], $this->auth)->assertUnprocessable();
});

it('can be dismissed and brought back', function () {
    $this->postJson('/api/setup-guide/dismiss', ['dismissed' => true], $this->auth)->assertJsonPath('data.dismissed', true);
    $this->postJson('/api/setup-guide/dismiss', ['dismissed' => false], $this->auth)->assertJsonPath('data.dismissed', false);
});

it('remembers dismissed tips', function () {
    $this->postJson('/api/setup-guide/tips', ['tip' => 'home_runway'], $this->auth)->assertJsonPath('data.tips_dismissed', ['home_runway']);
    $this->postJson('/api/setup-guide/tips', ['tip' => 'home_runway'], $this->auth)->assertJsonPath('data.tips_dismissed', ['home_runway']);
    $this->postJson('/api/setup-guide/tips', ['tip' => 'nope'], $this->auth)->assertUnprocessable();
});
