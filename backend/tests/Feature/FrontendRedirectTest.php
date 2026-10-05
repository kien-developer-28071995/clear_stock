<?php

// The backend is the API only: the app's pages are a separate site (frontend/). A browser that
// still reaches the backend is sent on, and the API can be called from the frontend's domain.

beforeEach(function () {
    config(['shopify.frontend_url' => 'https://app.clearstock.test', 'shopify.website_url' => 'https://clearstock.test']);
});

it('serves no page of the app: Shopify\'s request continues on the frontend with its query', function () {
    $this->get('/?shop=demo.myshopify.com&host=abc&embedded=1&id_token=xyz')
        ->assertRedirect('https://app.clearstock.test/?shop=demo.myshopify.com&host=abc&embedded=1&id_token=xyz');
    $this->get('/products/12?shop=demo.myshopify.com&embedded=1&tab=settings')
        ->assertRedirect('https://app.clearstock.test/products/12?shop=demo.myshopify.com&embedded=1&tab=settings');
    $this->get('/auth/callback?shop=demo.myshopify.com&code=abc')
        ->assertRedirect('https://app.clearstock.test/?shop=demo.myshopify.com&code=abc');
});

it('sends visitors without a shop to the website', function () {
    $this->get('/')->assertRedirect('https://clearstock.test/');
    $this->get('/settings')->assertRedirect('https://clearstock.test/');
});

it('falls back to the website when no frontend is configured', function () {
    config(['shopify.frontend_url' => null]);

    $this->get('/?shop=demo.myshopify.com&embedded=1')->assertRedirect('https://clearstock.test/');
});

it('lets the frontend\'s domain call the API and read the download headers', function () {
    $this->withHeaders(['Origin' => 'https://app.clearstock.test', 'Access-Control-Request-Method' => 'PUT', 'Access-Control-Request-Headers' => 'authorization,content-type'])
        ->options('/api/settings')
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', '*');

    // No session token: refused, but readable by the frontend (CORS headers on errors too).
    $this->withHeaders(['Origin' => 'https://app.clearstock.test'])->getJson('/api/shop')
        ->assertUnauthorized()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition, X-Skipped-Rows');
});

it('redirects the old privacy and support URLs to the website, in the requested language', function (string $path, string $website) {
    $this->get($path)->assertStatus(301)->assertRedirect($website);
})->with([
    ['/privacy', 'https://clearstock.test/privacy'],
    ['/support', 'https://clearstock.test/support'],
    ['/support?lang=vi', 'https://clearstock.test/vi/support'],
    ['/privacy?lang=en', 'https://clearstock.test/privacy'],
    ['/privacy?lang=xx', 'https://clearstock.test/privacy'],
]);
