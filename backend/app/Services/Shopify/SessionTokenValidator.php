<?php

namespace App\Services\Shopify;

use App\Exceptions\InvalidSessionTokenException;
use App\Support\ShopDomain;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Validates App Bridge session tokens as documented at
 * https://shopify.dev/docs/apps/build/authentication-authorization/session-tokens
 * (HS256 signed with the app secret; checks exp, nbf, aud, iss/dest).
 */
class SessionTokenValidator
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly int $leeway = 10,
    ) {}

    public function validate(?string $jwt): SessionToken
    {
        if ($jwt === null || $jwt === '' || $this->apiSecret === '') {
            throw new InvalidSessionTokenException('Missing session token.');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->leeway;
        try {
            // Verifies signature, exp, nbf and iat.
            $claims = (array) JWT::decode($jwt, new Key($this->apiSecret, 'HS256'));
        } catch (Throwable $e) {
            throw new InvalidSessionTokenException('Invalid session token: '.$e->getMessage(), previous: $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($this->apiKey, $audiences, true)) {
            throw new InvalidSessionTokenException('Session token audience mismatch.');
        }

        $destHost = parse_url((string) ($claims['dest'] ?? ''), PHP_URL_HOST);
        $issHost = parse_url((string) ($claims['iss'] ?? ''), PHP_URL_HOST);
        $shop = ShopDomain::normalize($destHost ?: null);
        if ($shop === null || $issHost !== $destHost) {
            throw new InvalidSessionTokenException('Session token issuer/destination mismatch.');
        }

        return new SessionToken(
            raw: $jwt,
            shopDomain: $shop,
            userId: isset($claims['sub']) ? (string) $claims['sub'] : null,
            sessionId: isset($claims['sid']) ? (string) $claims['sid'] : null,
            expiresAt: CarbonImmutable::createFromTimestamp((int) $claims['exp']),
        );
    }
}
