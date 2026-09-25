<?php

use App\Support\ShopDomain;

it('normalizes valid shop domains', function (string $input, string $expected) {
    expect(ShopDomain::normalize($input))->toBe($expected);
})->with([
    ['demo.myshopify.com', 'demo.myshopify.com'],
    ['  Demo-Store.MyShopify.com ', 'demo-store.myshopify.com'],
    ['https://demo.myshopify.com/', 'demo.myshopify.com'],
]);

it('rejects anything that is not a myshopify domain', function (?string $input) {
    expect(ShopDomain::normalize($input))->toBeNull();
})->with([null, '', 'evil.com', 'demo.myshopify.com.evil.com', '-bad.myshopify.com', 'demo.myshopify.com/../x']);
