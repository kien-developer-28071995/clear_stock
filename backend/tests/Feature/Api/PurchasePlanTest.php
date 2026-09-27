<?php

use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\Forecast\ForecastService;
use Carbon\CarbonImmutable;
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
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 100, perDay: 4, attrs: ['unit_cost' => 2.5]);
    $this->cup = product($this->shop, $this->location, 'Cup', stock: 50, perDay: 4, attrs: ['unit_cost' => null]);
    $this->vase = product($this->shop, $this->location, 'Vase', stock: 1000, perDay: 4, attrs: ['unit_cost' => 10]);
});

function purchasePlan(array $query = []): array
{
    app(ForecastService::class)->runForShop(test()->shop);

    return test()->getJson('/api/purchase-plan?'.http_build_query($query), test()->auth)->assertOk()->json('data');
}

it('plans every order of the next 12 weeks at the current sales rate', function () {
    $data = purchasePlan();
    $mug = collect($data['items'])->firstWhere('name', 'Mug');
    $cup = collect($data['items'])->firstWhere('name', 'Cup');

    // Mug reaches 84 on Sep 24 and orders 120 (up to 204); 30 days of sales later it is back at 84.
    expect($mug['orders'])->toBe([
        ['date' => '2026-09-24', 'qty' => 120],
        ['date' => '2026-10-24', 'qty' => 120],
        ['date' => '2026-11-23', 'qty' => 120],
    ])->and($mug['cost'])->toEqual(900)
        // Cup orders today from 50 in stock, then every 30 days.
        ->and($cup['orders'][0])->toBe(['date' => '2026-09-20', 'qty' => 154])
        ->and($cup['cost'])->toBeNull()
        ->and(collect($data['items'])->pluck('name')->all())->toBe(['Mug', 'Cup']);   // months of Vase stock: no order

    expect($data['totals'])->toMatchArray(['products' => 2, 'orders' => 6, 'units' => 360 + 154 + 240, 'missing_cost' => 1])
        ->and($data['totals']['cost'])->toEqual(900)
        ->and($data['by_week'])->toHaveCount(12)
        ->and($data['by_week'][0])->toMatchArray(['start' => '2026-09-20', 'orders' => 2, 'units' => 274, 'missing_cost' => 1])
        ->and($data['by_week'][4]['units'])->toBe(240);    // Oct 18-24: Cup Oct 20 + Mug Oct 24
});

it('uses the supplier order cycle and filters by supplier', function () {
    $acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'lead_time_days' => null, 'order_cycle_days' => 14]);
    $this->mug->update(['supplier_id' => $acme->id]);

    $data = purchasePlan(['supplier_id' => $acme->id, 'weeks' => 4]);

    // Order up to 4 x (21 + 14) = 140: 56 at a time, every 14 days.
    expect(collect($data['items'])->pluck('name')->all())->toBe(['Mug'])
        ->and($data['items'][0]['orders'])->toBe([['date' => '2026-09-24', 'qty' => 56], ['date' => '2026-10-08', 'qty' => 56]])
        ->and($data['by_supplier'][0])->toMatchArray(['name' => 'Acme', 'products' => 1, 'orders' => 2, 'units' => 112, 'first_order' => '2026-09-24'])
        ->and($data['by_week'])->toHaveCount(4);
});

it('is a Starter feature and can be switched off', function () {
    $this->shop->update(['plan' => 'free']);
    $this->getJson('/api/purchase-plan', $this->auth)->assertStatus(402)->assertJsonPath('params.feature', 'purchase_plan');

    $this->shop->update(['plan' => 'starter']);
    config(['features.purchase_plan' => false]);
    $this->getJson('/api/purchase-plan', $this->auth)->assertNotFound()->assertJsonPath('code', 'feature_disabled');
});

it('exports every planned order as CSV (Starter, needs PO export)', function () {
    app(ForecastService::class)->runForShop($this->shop);

    $csv = $this->get('/api/purchase-plan/export?weeks=4', $this->auth)->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    $lines = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));

    expect($lines[0])->toBe(['Order date', 'Week', 'Supplier', 'Product', 'SKU', 'Order quantity', 'Unit cost', 'Line total', 'Currency'])
        ->and(array_slice($lines, 1))->toHaveCount(2)
        ->and($lines[1])->toMatchArray([0 => '2026-09-20', 1 => '1', 3 => 'Cup', 5 => '154', 6 => ''])
        ->and($lines[2])->toMatchArray([0 => '2026-09-24', 3 => 'Mug', 5 => '120', 6 => '2.5', 7 => '300', 8 => 'USD']);

    config(['features.purchase_orders' => false]);
    $this->get('/api/purchase-plan/export', $this->auth)->assertNotFound();
});

it('leaves discontinued products out of the plan', function () {
    $this->mug->update(['discontinued' => true]);

    expect(collect(purchasePlan()['items'])->pluck('name')->all())->toBe(['Cup']);
});

it('places orders on the supplier order weekdays and lists them in an order calendar', function () {
    // Mug is due Thu 2026-09-24; Acme takes orders on Mondays: 09-21, then every order moves to a Monday.
    $acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'lead_time_days' => null, 'order_weekdays' => [1]]);
    $this->mug->update(['supplier_id' => $acme->id]);

    $data = purchasePlan(['weeks' => 8]);
    $mug = collect($data['items'])->firstWhere('name', 'Mug');

    expect(collect($mug['orders'])->pluck('date')->map(fn ($d) => CarbonImmutable::parse($d)->isoWeekday())->unique()->all())->toBe([1])
        ->and($mug['orders'][0]['date'])->toBe('2026-09-21')
        ->and($data['calendar'][0])->toMatchArray(['date' => '2026-09-20', 'supplier' => null, 'products' => 1])   // Cup, no supplier
        ->and($data['calendar'][1])->toMatchArray(['date' => '2026-09-21', 'supplier' => 'Acme', 'products' => 1, 'units' => $mug['orders'][0]['qty']])
        ->and(collect($data['calendar'])->sum('units'))->toBe($data['totals']['units']);
});

it('saves supplier order weekdays (sorted, every day = any day)', function () {
    $this->postJson('/api/suppliers', ['name' => 'Acme', 'order_weekdays' => [4, 1, 1]], $this->auth)->assertCreated()
        ->assertJsonPath('data.order_weekdays', [1, 4]);
    $id = Supplier::where('name', 'Acme')->value('id');
    $this->putJson("/api/suppliers/{$id}", ['name' => 'Acme', 'order_weekdays' => [1, 2, 3, 4, 5, 6, 7]], $this->auth)->assertOk()
        ->assertJsonPath('data.order_weekdays', null);
    $this->putJson("/api/suppliers/{$id}", ['name' => 'Acme', 'order_weekdays' => [8]], $this->auth)->assertUnprocessable();
});
