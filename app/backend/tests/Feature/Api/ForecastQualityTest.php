<?php

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\DailySale;
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
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'free']);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

describe('sales spikes', function () {
    beforeEach(function () {
        // 2/day with one wholesale order of 80 ten days ago.
        $this->mug = product($this->shop, $this->location, 'Mug', stock: 100, perDay: 2);
        DailySale::where('variant_id', $this->mug->id)->where('date', '2026-09-10')->update(['units_sold' => 80]);
    });

    it('caps one-off spikes by default and explains it', function () {
        app(ForecastService::class)->runForShop($this->shop);

        $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertOk()
            ->assertJsonPath('data.avg_daily_sales', 2)
            ->assertJsonPath('data.explanation_lines.1.code', 'spikes_capped')
            ->assertJsonPath('data.explanation_lines.1.params.top', 80);
    });

    it('can be switched off in Settings, which recomputes the forecasts', function () {
        $this->getJson('/api/settings', $this->auth)->assertJsonPath('data.filter_sales_spikes', true);
        $this->putJson('/api/settings', ['filter_sales_spikes' => false], $this->auth)->assertOk()->assertJsonPath('data.filter_sales_spikes', false);
        Queue::assertPushed(RecomputeForecasts::class);

        app(ForecastService::class)->runForShop($this->shop->fresh());
        expect((float) Forecast::where('variant_id', $this->mug->id)->value('avg_daily_sales'))->toBeGreaterThan(2.5);
    });

    it('is not applied when switched off app-wide', function () {
        config(['features.spike_filter' => false]);
        app(ForecastService::class)->runForShop($this->shop);

        expect((float) Forecast::where('variant_id', $this->mug->id)->value('avg_daily_sales'))->toBeGreaterThan(2.5);
        $this->getJson('/api/settings', $this->auth)->assertJsonPath('data.filter_sales_spikes', null);
    });
});

describe('lost sales', function () {
    beforeEach(function () {
        // Out of stock for the last 5 days, selling 4/day before.
        $this->mug = product($this->shop, $this->location, 'Mug', stock: 0, perDay: 4, attrs: ['price' => 12.5]);
        DailySale::where('variant_id', $this->mug->id)->where('date', '>=', '2026-09-15')->update(['units_sold' => 0, 'was_in_stock' => false]);
        $this->cup = product($this->shop, $this->location, 'Cup', stock: 100, perDay: 4, attrs: ['price' => 5]);
        app(ForecastService::class)->runForShop($this->shop);
    });

    it('estimates what out-of-stock days cost in the last 30 days', function () {
        $this->getJson('/api/dashboard', $this->auth)->assertOk()
            ->assertJsonPath('data.lost_sales.count', 1)
            ->assertJsonPath('data.lost_sales.units', 20)
            ->assertJsonPath('data.lost_sales.revenue', 250)
            ->assertJsonPath('data.lost_sales.top.0.name', 'Mug')
            ->assertJsonPath('data.lost_sales.top.0.out_of_stock_days', 5);
    });

    it('is hidden when switched off app-wide', function () {
        config(['features.lost_sales' => false]);

        $this->getJson('/api/dashboard', $this->auth)->assertJsonPath('data.lost_sales', null);
        $codes = collect($this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->json('data.explanation_lines'))->pluck('code');
        expect($codes)->not->toContain('lost_sales');
    });
});

it('saves a supplier order cycle and recomputes its products', function () {
    $acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme']);

    $this->putJson("/api/suppliers/{$acme->id}", ['name' => 'Acme', 'order_cycle_days' => 14], $this->auth)->assertOk()
        ->assertJsonPath('data.order_cycle_days', 14);
    Queue::assertPushed(RecomputeForecasts::class);

    $this->putJson("/api/suppliers/{$acme->id}", ['name' => 'Acme', 'order_cycle_days' => 0], $this->auth)->assertUnprocessable();
});
