<?php

use App\Exceptions\InvalidSessionTokenException;
use App\Services\Shopify\SessionTokenValidator;

beforeEach(function () {
    $this->validator = new SessionTokenValidator(TEST_API_KEY, TEST_API_SECRET, 10);
});

it('accepts a valid session token and extracts the shop', function () {
    $session = $this->validator->validate(sessionToken('My-Store.myshopify.com'));

    expect($session->shopDomain)->toBe('my-store.myshopify.com')
        ->and($session->userId)->toBe('42')
        ->and($session->sessionId)->toBe('session-id');
});

it('rejects invalid tokens', function (?string $token) {
    $this->validator->validate($token);
})->throws(InvalidSessionTokenException::class)->with([
    'missing' => [null],
    'empty' => [''],
    'garbage' => ['not-a-jwt'],
    'expired' => fn () => sessionToken(overrides: ['exp' => time() - 60]),
    'not yet valid' => fn () => sessionToken(overrides: ['nbf' => time() + 120]),
    'wrong secret' => fn () => sessionToken(secret: 'another-secret-another-secret-00'),
    'wrong audience' => fn () => sessionToken(overrides: ['aud' => 'other-app']),
    'iss/dest mismatch' => fn () => sessionToken(overrides: ['iss' => 'https://evil.myshopify.com/admin']),
    'dest not myshopify' => fn () => sessionToken(overrides: ['iss' => 'https://evil.com/admin', 'dest' => 'https://evil.com']),
]);

it('tolerates small clock skew', function () {
    $session = $this->validator->validate(sessionToken(overrides: ['nbf' => time() + 5]));

    expect($session->shopDomain)->toBe('demo.myshopify.com');
});
