<?php

use App\Models\Shop;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // The Blade shell uses @vite; don't require a real build in tests.
    $this->withoutVite();
});

it('renders the embedded shell with App Bridge and a frame-ancestors CSP', function () {
    Shop::factory()->create(['domain' => 'demo.myshopify.com']);

    $this->get('/?shop=demo.myshopify.com&host=abc&embedded=1')
        ->assertOk()
        ->assertSee('<meta name="shopify-api-key" content="'.TEST_API_KEY.'">', false)
        ->assertSee('https://cdn.shopify.com/shopifycloud/app-bridge.js', false)
        ->assertHeader('Content-Security-Policy', 'frame-ancestors https://demo.myshopify.com https://admin.shopify.com;');
});

it('redirects to the embedded admin URL when opened outside the admin', function () {
    $this->get('/?shop=demo.myshopify.com')
        ->assertRedirect('https://demo.myshopify.com/admin/apps/'.TEST_API_KEY.'/');
});

it('serves client-side routes through the same shell', function () {
    $this->get('/settings?shop=demo.myshopify.com&embedded=1')->assertOk()->assertSee('id="root"', false);
});

it('sends visitors without a shop to the website and forbids framing', function () {
    config(['shopify.website_url' => 'https://clearstock.test']);

    $this->get('/')
        ->assertRedirect('https://clearstock.test/')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none';");
});

it('exchanges the id_token on first load so the install completes immediately', function () {
    Http::fake([
        '*/admin/oauth/access_token' => Http::response(tokenResponse()),
        '*/graphql.json' => Http::response(['data' => ['shop' => ['name' => 'Demo', 'currencyCode' => 'USD', 'ianaTimezone' => 'UTC']]]),
    ]);

    $this->get('/?shop=demo.myshopify.com&embedded=1&id_token='.sessionToken())->assertOk();

    expect(Shop::firstWhere('domain', 'demo.myshopify.com')?->isInstalled())->toBeTrue();
});

it('still renders the shell when the id_token is invalid', function () {
    Http::fake();

    $this->get('/?shop=demo.myshopify.com&embedded=1&id_token=bad')->assertOk();

    expect(Shop::count())->toBe(0);
});

it('redirects the old privacy and support URLs to the website, in the requested language', function (string $path, string $website) {
    config(['shopify.website_url' => 'https://clearstock.test']);

    $this->get($path)->assertStatus(301)->assertRedirect($website);
})->with([
    ['/privacy', 'https://clearstock.test/privacy'],
    ['/support', 'https://clearstock.test/support'],
    ['/support?lang=vi', 'https://clearstock.test/vi/support'],
    ['/privacy?lang=en', 'https://clearstock.test/privacy'],
    ['/privacy?lang=xx', 'https://clearstock.test/privacy'],
]);
