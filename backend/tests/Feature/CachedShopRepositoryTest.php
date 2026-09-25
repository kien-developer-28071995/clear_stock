<?php

use App\Models\Shop;
use App\Repositories\Cache\CachedShopRepository;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

it('binds the cached decorator', function () {
    expect(app(ShopRepositoryInterface::class))->toBeInstanceOf(CachedShopRepository::class);
});

it('caches lookups and invalidates them when the shop changes', function () {
    $repo = app(ShopRepositoryInterface::class);
    $shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'name' => 'Old']);

    expect($repo->findByDomain('demo.myshopify.com')->name)->toBe('Old');
    expect(Cache::has(CacheKeys::shopByDomain('demo.myshopify.com')))->toBeTrue();

    $repo->update($shop, ['name' => 'New']);

    expect(Cache::has(CacheKeys::shopByDomain('demo.myshopify.com')))->toBeFalse()
        ->and($repo->findByDomain('demo.myshopify.com')->name)->toBe('New');
});

it('does not cache misses', function () {
    $repo = app(ShopRepositoryInterface::class);

    expect($repo->findByDomain('new.myshopify.com'))->toBeNull();
    Shop::factory()->create(['domain' => 'new.myshopify.com']);

    expect($repo->findByDomain('new.myshopify.com'))->not->toBeNull();
});
