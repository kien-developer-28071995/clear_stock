<?php

use App\Models\Location;
use App\Models\ManualOrder;
use App\Models\Shop;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'starter',
        'default_lead_time_days' => 14, 'default_safety_days' => 7, 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];

    // All due now, 4/day, order up to 204:
    // Mug out of stock (204 x $1 = 204), Cup runs out in 10 days, before a delivery in 14 (164 x $2 = 328),
    // Vase 20 days of stock, B class (124 x $1 = 124), Bowl same, A class (124, no cost).
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 0, perDay: 4, attrs: ['unit_cost' => 1]);
    $this->cup = product($this->shop, $this->location, 'Cup', stock: 40, perDay: 4, attrs: ['unit_cost' => 2]);
    $this->vase = product($this->shop, $this->location, 'Vase', stock: 80, perDay: 4, attrs: ['unit_cost' => 1]);
    $this->bowl = product($this->shop, $this->location, 'Bowl', stock: 80, perDay: 4, attrs: ['unit_cost' => null]);
    app(ForecastService::class)->runForShop($this->shop);
    $this->vase->update(['abc_class' => 'B']);
    $this->bowl->update(['abc_class' => 'A']);
});

it('ranks what is due and fills the monthly budget left, with a reason for each', function () {
    $data = $this->putJson('/api/budget', ['budget' => 450], $this->auth)->assertOk()->json('data');

    expect(collect($data['items'])->map(fn ($i) => [$i['name'], $i['reason'], $i['in_budget']])->all())->toBe([
        ['Mug', 'out_of_stock', true],               // 204, 246 left
        ['Cup', 'runs_out_before_delivery', false],  // 328 does not fit
        ['Bowl', 'reorder_point', true],             // A class first; no cost, counted in
        ['Vase', 'reorder_point', true],             // 124 fits the 246 left
    ])->and($data['remaining'])->toEqual(450)
        ->and($data['totals'])->toMatchArray(['products' => 4, 'in_budget' => 3, 'waiting' => 1, 'waiting_cost' => 328.0, 'missing_cost' => 1]);
    expect($this->shop->fresh()->order_budget)->toBe('450.00');
});

it('counts orders marked as placed this month as spent', function () {
    ManualOrder::factory()->for($this->shop)->create(['variant_id' => $this->vase->id, 'quantity' => 100, 'ordered_on' => '2026-09-05']); // $100
    ManualOrder::factory()->for($this->shop)->create(['variant_id' => $this->vase->id, 'quantity' => 999, 'ordered_on' => '2026-08-30']); // last month
    $this->shop->update(['order_budget' => 400]);

    $data = $this->getJson('/api/budget', $this->auth)->assertOk()->json('data');
    expect($data['spent'])->toMatchArray(['orders' => 1, 'cost' => 100.0])->and($data['remaining'])->toEqual(300);
});

it('shows planned spend per month against the budget in the purchase plan', function () {
    $this->shop->update(['order_budget' => 500]);
    $plan = $this->getJson('/api/purchase-plan?weeks=8', $this->auth)->assertOk()->json('data');

    expect($plan['budget'])->toEqual(500)
        ->and(collect($plan['by_month'])->pluck('month')->all())->toBe(['2026-09', '2026-10'])
        ->and(collect($plan['by_month'])->sum('cost'))->toEqual($plan['totals']['cost']);
});

it('is a Starter feature and can be switched off', function () {
    $this->shop->update(['plan' => 'free']);
    $this->getJson('/api/budget', $this->auth)->assertStatus(402)->assertJsonPath('params.feature', 'order_budget');
    $this->shop->update(['plan' => 'starter']);
    config(['features.order_budget' => false]);
    $this->getJson('/api/budget', $this->auth)->assertNotFound();
});
