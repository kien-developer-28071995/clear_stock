<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyReauthorizeException;
use App\Models\Shop;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps each shop's expiring offline access token valid.
 * Exchange and refresh share one lock per shop: doing both at once for the
 * same store would retire one of the resulting tokens.
 */
class ShopTokenService
{
    public function __construct(
        private readonly ShopifyOAuthClient $oauth,
        private readonly ShopRepositoryInterface $shops,
        private readonly Cache $cache,
        private readonly int $refreshMargin = 300,
    ) {}

    /** Exchange an ID token and persist the new token set. */
    public function exchange(string $shopDomain, string $idToken, array $extraAttributes = []): Shop
    {
        return $this->withLock($shopDomain, function () use ($shopDomain, $idToken, $extraAttributes) {
            $tokens = $this->oauth->exchangeSessionToken($shopDomain, $idToken);

            return $this->shops->updateOrCreateByDomain(
                $shopDomain,
                $tokens->toShopAttributes() + $extraAttributes,
            );
        });
    }

    /**
     * A usable access token for background work (jobs, webhooks).
     *
     * @throws ShopifyReauthorizeException when the merchant must open the app again
     */
    public function accessToken(Shop $shop): string
    {
        if (! $shop->accessTokenNeedsRefresh($this->refreshMargin)) {
            return $shop->access_token;
        }

        return $this->withLock($shop->domain, function () use ($shop) {
            // Another worker may have refreshed while we waited for the lock: re-read from DB.
            $fresh = $shop->fresh() ?? $shop;
            if (! $fresh->accessTokenNeedsRefresh($this->refreshMargin)) {
                $shop->setRawAttributes($fresh->getAttributes(), true);

                return $fresh->access_token;
            }

            if (! $fresh->canRefreshToken()) {
                throw new ShopifyReauthorizeException("No usable refresh token for {$shop->domain}.");
            }

            try {
                $tokens = $this->oauth->refreshAccessToken($fresh->domain, $fresh->refresh_token);
            } catch (ShopifyReauthorizeException $e) {
                Log::warning('Shopify refresh token rejected; merchant must reopen the app.', ['shop' => $shop->domain]);
                $this->shops->update($fresh, ['access_token' => null, 'access_token_expires_at' => null, 'refresh_token' => null, 'refresh_token_expires_at' => null]);
                throw $e;
            }

            $this->shops->update($fresh, $tokens->toShopAttributes());
            $shop->setRawAttributes($fresh->getAttributes(), true);

            return $tokens->accessToken;
        });
    }

    private function withLock(string $shopDomain, callable $callback): mixed
    {
        // Wait up to 15s for a concurrent exchange/refresh to finish.
        return $this->cache->lock(CacheKeys::shopTokenRefreshLock($shopDomain), 30)->block(15, $callback);
    }
}
