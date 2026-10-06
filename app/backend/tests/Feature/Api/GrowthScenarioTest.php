<?php

use App\Models\Forecast;
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
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'starter',
        'default_lead_time_days' => 14, 'default_safety_days' => 7]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];

    // 4/day, lead 14 + safety 7: reorder point 84, order up to 4 x (21 + 30) = 204.
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 100, perDay: 4, attrs: ['unit_cost' => 2.5]);   // order in 4 days
    $this->cup = product($this->shop, $this->location, 'Cup', stock: 50, perDay: 4, attrs: ['unit_cost' => null]);    // order today
    $this->vase = product($this->shop, $this->location, 'Vase', stock: 1000, perDay: 4, attrs: ['unit_cost' => 10]); // months of stock
    app(ForecastService::class)->runForShop($this->shop);
});

function whatIf(array $query): array
{
    return test()->getJson('/api/what-if?'.http_build_query($query), test()->auth)->assertOk()->json('data');
}

it('matches the current forecast when sales do not change', function () {
    $data = whatIf(['growth' => 0]);

    expect(array_column($data['items'], 'name'))->toBe(['Cup', 'Mug']);
    foreach ($data['items'] as $item) {
        expect($item['scenario'])->toBe($item['now']);
    }
    // Ordered today: the stored suggestion.
    $cup = collect($data['items'])->firstWhere('name', 'Cup');
    expect($cup['now']['order_qty'])->toBe(Forecast::where('variant_id', $this->cup->id)->whereNull('location_id')->value('suggested_qty'))
        ->and($data['totals']['now'])->toBe($data['totals']['scenario']);
});

it('recomputes order dates, quantities and money when sales grow', function () {
    $data = whatIf(['growth' => 50, 'horizon' => 30]);
    $mug = collect($data['items'])->firstWhere('name', 'Mug');

    // Now: 100 in stock reaches the reorder point 84 in 4 days; then orders 204 - 84 = 120.
    expect($mug['now'])->toMatchArray(['avg' => 4, 'order_date' => '2026-09-24', 'order_qty' => 120, 'stockout_risk' => false])
        // +50%: 6/day, reorder point 126 > 100 -> order today up to 6 x 51 = 306: 206 units.
        ->and($mug['scenario'])->toMatchArray(['avg' => 6, 'order_date' => '2026-09-20', 'order_qty' => 206, 'stockout_date' => '2026-10-06'])
        ->and($data['factor'])->toBe(1.5)
        ->and($data['until'])->toBe('2026-10-20');

    expect($data['totals']['now'])->toMatchArray(['products' => 2, 'order_today' => 1, 'cost' => 300, 'missing_cost' => 1])
        ->and($data['totals']['scenario'])->toMatchArray(['products' => 2, 'order_today' => 2, 'cost' => 515, 'missing_cost' => 1]);
});

it('flags products that would run out before an order placed today arrives', function () {
    // +100%: 8/day, 100 units last 12 days < 14 days lead time.
    $mug = collect(whatIf(['growth' => 100])['items'])->firstWhere('name', 'Mug');

    expect($mug['scenario']['stockout_risk'])->toBeTrue()->and($mug['now']['stockout_risk'])->toBeFalse();
});

it('only counts orders due within the horizon', function () {
    expect(array_column(whatIf(['growth' => 0, 'horizon' => 0])['items'], 'name'))->toBe(['Cup'])
        ->and(array_column(whatIf(['growth' => 50, 'horizon' => 0])['items'], 'name'))->toBe(['Cup', 'Mug']);
});

it('pushes orders later when sales drop', function () {
    $mug = collect(whatIf(['growth' => -50])['items'])->firstWhere('name', 'Mug');

    // 2/day: reorder point 42, reached in floor(58 / 2) = 29 days.
    expect($mug['scenario'])->toMatchArray(['avg' => 2, 'order_date' => '2026-10-19']);
});

it('filters by supplier and ABC class', function () {
    $acme = Supplier::factory()->for($this->shop)->create();
    $this->mug->update(['supplier_id' => $acme->id]);
    $this->cup->forceFill(['abc_class' => 'C'])->save();
    $this->mug->forceFill(['abc_class' => 'A'])->save();

    expect(array_column(whatIf(['growth' => 0, 'supplier_id' => $acme->id])['items'], 'name'))->toBe(['Mug'])
        ->and(array_column(whatIf(['growth' => 0, 'abc' => 'C'])['items'], 'name'))->toBe(['Cup']);
});

it('validates the scenario', function () {
    $this->getJson('/api/what-if?growth=600', $this->auth)->assertUnprocessable()->assertJsonValidationErrors('growth');
    $this->getJson('/api/what-if?growth=10&horizon=7', $this->auth)->assertUnprocessable()->assertJsonValidationErrors('horizon');
    $this->getJson('/api/what-if', $this->auth)->assertUnprocessable();
});

it('exports the scenario orders as a CSV', function () {
    app(ForecastService::class)->runForShop($this->shop);
    $csv = $this->get('/api/what-if/export?growth=50&horizon=30', $this->auth)->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    $lines = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));
    $data = $this->getJson('/api/what-if?growth=50&horizon=30', $this->auth)->json('data');

    expect($lines[0][0])->toBe('Order date')
        ->and(count($lines) - 1)->toBe($data['totals']['scenario']['products'])
        ->and(array_sum(array_map(fn ($l) => (int) $l[5], array_slice($lines, 1))))->toBe($data['totals']['scenario']['units']);
});
