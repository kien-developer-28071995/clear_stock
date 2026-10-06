<?php

use App\Models\AlertSetting;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SyncRun;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Repositories\Contracts\SyncRunRepositoryInterface;
use App\Services\Forecast\ForecastService;

/**
 * Cache repositories must survive a real serialize/unserialize round trip
 * (tests run the array store with serialization, like Redis in production).
 * Each read happens twice: the second one is served from the cache.
 */
it('reads cached models back as real objects', function () {
    $shop = Shop::factory()->create(['domain' => 'demo.myshopify.com']);
    Supplier::factory()->for($shop)->create();
    AlertSetting::factory()->for($shop)->create();
    SyncRun::create(['shop_id' => $shop->id, 'type' => 'initial', 'status' => 'completed', 'stage' => 'completed', 'window_start' => '2026-01-01', 'started_at' => now()]);
    product($shop, Location::factory()->for($shop)->create(), 'Mug', stock: 5, perDay: 2);
    app(ForecastService::class)->runForShop($shop);
    $today = now()->toDateString();

    foreach ([1, 2] as $pass) {
        expect(app(ShopRepositoryInterface::class)->findByDomain('demo.myshopify.com'))->toBeInstanceOf(Shop::class)
            ->and(app(SupplierRepositoryInterface::class)->allForShop($shop)->first())->toBeInstanceOf(Supplier::class)
            ->and(app(AlertSettingRepositoryInterface::class)->forShop($shop))->toBeInstanceOf(AlertSetting::class)
            ->and(app(SyncRunRepositoryInterface::class)->latestForShop($shop))->toBeInstanceOf(SyncRun::class)
            ->and(app(ForecastQueryRepositoryInterface::class)->actionItems($shop, $today, 5)->first()->variant->product_title)->toBe('Mug')
            ->and(app(ForecastQueryRepositoryInterface::class)->runway($shop, 5)->first()->variant->product_title)->toBe('Mug')
            ->and(app(ForecastQueryRepositoryInterface::class)->counts($shop, $today)['total'])->toBe(1);
    }
});

it('serves the dashboard and supplier list from cache on repeat requests', function () {
    Http::fake();
    $shop = Shop::factory()->create(['domain' => 'demo.myshopify.com']);
    Supplier::factory()->for($shop)->create(['name' => 'Acme']);
    $auth = ['Authorization' => 'Bearer '.sessionToken()];

    foreach ([1, 2] as $pass) {
        $this->getJson('/api/dashboard', $auth)->assertOk();
        $this->getJson('/api/suppliers', $auth)->assertOk()->assertJsonPath('data.0.name', 'Acme');
        $this->getJson('/api/sync', $auth)->assertOk();
    }
});
