<?php

namespace App\Services\Shopify;

use Carbon\CarbonImmutable;

final readonly class AccessTokenResponse
{
    public function __construct(
        public string $accessToken,
        public ?string $scope,
        public ?CarbonImmutable $expiresAt,
        public ?string $refreshToken,
        public ?CarbonImmutable $refreshTokenExpiresAt,
    ) {}

    public static function fromArray(array $data): self
    {
        $now = CarbonImmutable::now();
        $in = fn (?string $key) => isset($data[$key]) && (int) $data[$key] > 0 ? $now->addSeconds((int) $data[$key]) : null;

        return new self(
            accessToken: (string) $data['access_token'],
            scope: $data['scope'] ?? null,
            expiresAt: $in('expires_in'),
            refreshToken: $data['refresh_token'] ?? null,
            refreshTokenExpiresAt: $in('refresh_token_expires_in'),
        );
    }

    /** Attributes to persist on the shops table. */
    public function toShopAttributes(): array
    {
        return array_filter([
            'access_token' => $this->accessToken,
            'access_token_expires_at' => $this->expiresAt,
            'refresh_token' => $this->refreshToken,
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt,
            'scopes' => $this->scope,
        ], fn ($v) => $v !== null) + [
            // Always overwrite expiry so a non-expiring token never keeps a stale date.
            'access_token_expires_at' => $this->expiresAt,
        ];
    }
}
