<?php

use App\Exceptions\ShopifyReauthorizeException;
use App\Models\Shop;
use App\Services\Shopify\ShopTokenService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('returns the stored token while it is still valid', function () {
    Http::fake();
    $shop = Shop::factory()->create(['access_token' => 'shpat_current', 'access_token_expires_at' => now()->addHour()]);

    expect(app(ShopTokenService::class)->accessToken($shop))->toBe('shpat_current');
    Http::assertNothingSent();
});

it('refreshes an expiring offline token before it expires', function () {
    Http::fake(['*/admin/oauth/access_token' => Http::response(tokenResponse('shpat_refreshed', ['refresh_token' => 'shprt_rotated']))]);
    $shop = Shop::factory()->create([
        'access_token' => 'shpat_old',
        'access_token_expires_at' => now()->addMinutes(2), // inside the 5 min margin
        'refresh_token' => 'shprt_old',
    ]);

    expect(app(ShopTokenService::class)->accessToken($shop))->toBe('shpat_refreshed');

    Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'shprt_old');
    $shop->refresh();
    expect($shop->access_token)->toBe('shpat_refreshed')
        ->and($shop->refresh_token)->toBe('shprt_rotated');
});

it('clears tokens and asks for re-authorization when the refresh token is rejected', function () {
    Http::fake(['*/admin/oauth/access_token' => Http::response(['error' => 'invalid_grant'], 401)]);
    $shop = Shop::factory()->create(['access_token_expires_at' => now()->subMinute()]);

    expect(fn () => app(ShopTokenService::class)->accessToken($shop))->toThrow(ShopifyReauthorizeException::class);

    $shop->refresh();
    expect($shop->access_token)->toBeNull()->and($shop->refresh_token)->toBeNull();
});

it('does not try to refresh with an expired refresh token', function () {
    Http::fake();
    $shop = Shop::factory()->create([
        'access_token_expires_at' => now()->subMinute(),
        'refresh_token_expires_at' => now()->subDay(),
    ]);

    expect(fn () => app(ShopTokenService::class)->accessToken($shop))->toThrow(ShopifyReauthorizeException::class);
    Http::assertNothingSent();
});
