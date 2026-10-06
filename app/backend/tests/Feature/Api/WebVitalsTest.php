<?php

use App\Models\Shop;
use App\Models\WebVital;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'access_token_expires_at' => now()->addYear()]);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

it('stores the web vitals App Bridge reports, with the route pattern only', function () {
    $this->postJson('/api/web-vitals', ['page' => '/products/8123?tab=settings&q=<script>', 'metrics' => [
        ['name' => 'LCP', 'value' => 1830.5, 'id' => 'v1'], ['name' => 'CLS', 'value' => 0.0213], ['name' => 'XSS', 'value' => 1],
    ]], $this->auth)->assertNoContent();

    expect(DB::table('web_vitals')->orderBy('id')->get(['shop_id', 'metric', 'value', 'page'])->map(fn ($r) => (array) $r)->all())->toEqual([
        ['shop_id' => $this->shop->id, 'metric' => 'LCP', 'value' => '1830.5000', 'page' => '/products/:id'],
        ['shop_id' => $this->shop->id, 'metric' => 'CLS', 'value' => '0.0213', 'page' => '/products/:id'],
    ]);
});

it('needs a session and sane values, and is pruned after 35 days', function () {
    $this->postJson('/api/web-vitals', ['page' => '/', 'metrics' => [['name' => 'LCP', 'value' => 100]]])->assertUnauthorized();
    $this->postJson('/api/web-vitals', ['page' => '/', 'metrics' => [['name' => 'LCP', 'value' => -5]]], $this->auth)->assertStatus(422);
    $this->postJson('/api/web-vitals', ['page' => '/', 'metrics' => array_fill(0, 11, ['name' => 'LCP', 'value' => 1])], $this->auth)->assertStatus(422);

    DB::table('web_vitals')->insert([
        ['shop_id' => $this->shop->id, 'metric' => 'LCP', 'value' => 1, 'page' => '/', 'created_at' => now()->subDays(40)],
        ['shop_id' => $this->shop->id, 'metric' => 'LCP', 'value' => 2, 'page' => '/', 'created_at' => now()->subDays(3)],
    ]);
    $this->artisan('model:prune', ['--model' => [WebVital::class]])->assertSuccessful();
    expect(DB::table('web_vitals')->pluck('value')->map(fn ($v) => (float) $v)->all())->toBe([2.0]);
});
