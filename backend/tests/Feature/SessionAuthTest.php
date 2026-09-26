<?php

use App\Enums\SyncStatus;
use App\Events\ShopInstalled;
use App\Models\Shop;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function fakeShopify(array $token = []): void
{
    Http::fake([
        'demo.myshopify.com/admin/oauth/access_token' => Http::response(tokenResponse(...$token)),
        'demo.myshopify.com/admin/api/*/graphql.json' => Http::response(['data' => ['shop' => [
            'name' => 'Demo Store', 'currencyCode' => 'EUR', 'ianaTimezone' => 'Europe/Berlin',
        ]]]),
    ]);
}

it('installs the shop on the first API call via token exchange', function () {
    Event::fake([ShopInstalled::class]);
    fakeShopify();

    $this->getJson('/api/shop', ['Authorization' => 'Bearer '.sessionToken()])
        ->assertOk()
        ->assertJsonPath('data.domain', 'demo.myshopify.com')
        ->assertJsonPath('data.name', 'Demo Store')
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.timezone', 'Europe/Berlin')
        ->assertJsonPath('data.plan', 'free')
        ->assertJsonMissingPath('data.access_token');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/admin/oauth/access_token')
        && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
        && $r['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token'
        && $r['expiring'] === '1'
        && $r['client_id'] === TEST_API_KEY);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/api/2026-07/graphql.json')
        && $r->header('X-Shopify-Access-Token')[0] === 'shpat_new');

    $shop = Shop::firstWhere('domain', 'demo.myshopify.com');
    expect($shop->access_token)->toBe('shpat_new')
        ->and($shop->refresh_token)->toBe('shprt_new')
        ->and($shop->access_token_expires_at->isFuture())->toBeTrue()
        ->and($shop->installed_at)->not->toBeNull()
        ->and($shop->sync_status)->toBe(SyncStatus::Pending);

    // Encrypted at rest.
    $raw = DB::table('shops')->value('access_token');
    expect($raw)->not->toContain('shpat_new');

    Event::assertDispatched(ShopInstalled::class);
});

it('does not call Shopify when the shop already has a valid token', function () {
    Http::fake();
    Shop::factory()->create(['domain' => 'demo.myshopify.com']);

    $this->getJson('/api/shop', ['Authorization' => 'Bearer '.sessionToken()])->assertOk();

    Http::assertNothingSent();
});

it('re-exchanges when the offline token has expired during a merchant session', function () {
    fakeShopify();
    Shop::factory()->create(['domain' => 'demo.myshopify.com', 'access_token' => 'shpat_old', 'access_token_expires_at' => now()->subMinute()]);

    $this->getJson('/api/shop', ['Authorization' => 'Bearer '.sessionToken()])->assertOk();

    expect(Shop::first()->access_token)->toBe('shpat_new');
    Http::assertSentCount(1); // exchange only, not a reinstall
});

it('re-exchanges when required scopes are missing', function () {
    fakeShopify();
    Shop::factory()->create(['domain' => 'demo.myshopify.com', 'scopes' => 'read_products']);

    $this->getJson('/api/shop', ['Authorization' => 'Bearer '.sessionToken()])->assertOk();

    expect(Shop::first()->scopes)->toContain('read_orders');
});

it('re-checks missing scopes at most every 10 minutes and never fails the request over it', function () {
    // Shopify still grants the old scopes (the merchant hasn't approved the new ones yet).
    Http::fake(['*/admin/oauth/access_token' => Http::response(tokenResponse('shpat_same', ['scope' => 'read_products']))]);
    Shop::factory()->create(['domain' => 'demo.myshopify.com', 'scopes' => 'read_products']);

    foreach (range(1, 3) as $i) {
        $this->getJson('/api/shop', ['Authorization' => 'Bearer '.sessionToken()])->assertOk();
    }
    Http::assertSentCount(1);

    // A failing re-check keeps the (still valid) current token.
    $this->travel(11)->minutes();
    Http::fake(['*/admin/oauth/access_token' => Http::response('down', 503)]);
    Shop::first()->update(['access_token_expires_at' => now()->addHour()]);
    $this->getJson('/api/shop', ['Authorization' => 'Bearer '.sessionToken()])->assertOk();
});

it('reinstalls a previously uninstalled shop', function () {
    Event::fake([ShopInstalled::class]);
    fakeShopify();
    Shop::factory()->uninstalled()->create(['domain' => 'demo.myshopify.com', 'installed_at' => now()->subYear()]);

    $this->getJson('/api/shop', ['Authorization' => 'Bearer '.sessionToken()])->assertOk();

    $shop = Shop::first();
    expect($shop->uninstalled_at)->toBeNull()
        ->and($shop->installed_at->isToday())->toBeTrue();
    Event::assertDispatched(ShopInstalled::class);
});

it('returns 401 with the App Bridge retry header for a bad session token', function (array $headers) {
    Http::fake();

    $this->getJson('/api/shop', $headers)
        ->assertUnauthorized()
        ->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');

    Http::assertNothingSent();
})->with([
    'no header' => [[]],
    'expired' => fn () => ['Authorization' => 'Bearer '.sessionToken(overrides: ['exp' => time() - 120])],
    'forged' => fn () => ['Authorization' => 'Bearer '.sessionToken(secret: 'forged-secret-forged-secret-0000')],
]);

it('asks App Bridge to retry when Shopify rejects the ID token during exchange', function () {
    Http::fake(['*/admin/oauth/access_token' => Http::response(['error' => 'invalid_subject_token'], 400)]);

    $this->getJson('/api/shop', ['Authorization' => 'Bearer '.sessionToken()])
        ->assertUnauthorized()
        ->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');

    expect(Shop::count())->toBe(0);
});

it('returns 502 when Shopify is down during exchange', function () {
    Http::fake(['*/admin/oauth/access_token' => Http::response('oops', 503)]);

    $this->getJson('/api/shop', ['Authorization' => 'Bearer '.sessionToken()])->assertStatus(502);
});

it('still installs when loading shop details fails', function () {
    Http::fake([
        '*/admin/oauth/access_token' => Http::response(tokenResponse()),
        '*/graphql.json' => Http::response(['errors' => [['message' => 'boom']]]),
    ]);

    $this->getJson('/api/shop', ['Authorization' => 'Bearer '.sessionToken()])
        ->assertOk()
        ->assertJsonPath('data.timezone', 'UTC');
});
