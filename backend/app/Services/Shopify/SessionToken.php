<?php

namespace App\Services\Shopify;

use Carbon\CarbonImmutable;

/** Validated claims of an App Bridge session token (ID token). */
final readonly class SessionToken
{
    public function __construct(
        public string $raw,
        public string $shopDomain,
        public ?string $userId,
        public ?string $sessionId,
        public CarbonImmutable $expiresAt,
    ) {}
}
