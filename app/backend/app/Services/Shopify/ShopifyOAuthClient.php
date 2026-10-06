<?php

namespace App\Services\Shopify;

use App\Exceptions\InvalidSessionTokenException;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyReauthorizeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

/**
 * Talks to https://{shop}/admin/oauth/access_token:
 *  - token exchange (ID token -> expiring offline access token)
 *  - refresh of an expiring offline access token
 * https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens
 */
class ShopifyOAuthClient
{
    public function __construct(
        private readonly Http $http,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly int $timeout = 30,
    ) {}

    public function exchangeSessionToken(string $shopDomain, string $idToken): AccessTokenResponse
    {
        $response = $this->post($shopDomain, [
            'client_id' => $this->apiKey,
            'client_secret' => $this->apiSecret,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $idToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            // Public apps must use expiring offline tokens.
            'expiring' => '1',
        ]);

        // 400 = ID token expired/invalid: App Bridge should fetch a new one and retry.
        if ($response->status() === 400) {
            throw new InvalidSessionTokenException('Shopify rejected the session token during token exchange.');
        }

        return $this->parse($response, 'Token exchange');
    }

    public function refreshAccessToken(string $shopDomain, string $refreshToken): AccessTokenResponse
    {
        $response = $this->post($shopDomain, [
            'client_id' => $this->apiKey,
            'client_secret' => $this->apiSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->status() === 401 || $response->status() === 400) {
            throw new ShopifyReauthorizeException("Refresh token rejected for {$shopDomain}.");
        }

        return $this->parse($response, 'Token refresh');
    }

    private function post(string $shopDomain, array $form): Response
    {
        try {
            return $this->http->asForm()
                ->acceptJson()
                ->timeout($this->timeout)
                ->post("https://{$shopDomain}/admin/oauth/access_token", $form);
        } catch (ConnectionException $e) {
            throw new ShopifyApiException("Could not reach {$shopDomain}: {$e->getMessage()}", retryable: true);
        }
    }

    private function parse(Response $response, string $what): AccessTokenResponse
    {
        if ($response->status() === 429 || $response->serverError()) {
            throw new ShopifyApiException("{$what} failed with HTTP {$response->status()}.", retryable: true);
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new ShopifyApiException("{$what} failed with HTTP {$response->status()}.");
        }

        return AccessTokenResponse::fromArray($response->json());
    }
}
