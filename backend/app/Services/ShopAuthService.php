<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Events\ShopInstalled;
use App\Exceptions\InvalidSessionTokenException;
use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Shopify\SessionToken;
use App\Services\Shopify\SessionTokenValidator;
use App\Services\Shopify\ShopTokenService;
use App\Support\CacheKeys;
use App\Support\Monitor;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Turns an App Bridge session token into an authenticated, installed Shop.
 * Installation is Shopify-managed: the first valid session token after install
 * triggers a token exchange right away (no redirect-based OAuth).
 */
class ShopAuthService
{
    private const SCOPE_RECHECK_SECONDS = 600;

    public function __construct(
        private readonly SessionTokenValidator $validator,
        private readonly ShopRepositoryInterface $shops,
        private readonly ShopTokenService $tokens,
        private readonly ShopService $shopService,
        private readonly Cache $cache,
        private readonly string $requiredScopes,
        private readonly int $refreshMargin = 300,
    ) {}

    /**
     * @throws InvalidSessionTokenException
     * @throws ShopifyApiException
     */
    public function authenticate(?string $idToken): Shop
    {
        $session = $this->validator->validate($idToken);
        $shop = $this->shops->findByDomain($session->shopDomain);

        if ($shop === null || $this->needsTokenExchange($shop)) {
            return $this->install($session, $shop);
        }

        // New scopes declared but not granted on this token yet (e.g. right after an app update,
        // before the merchant approves them). The current token still works: re-exchange at most
        // every few minutes to pick up the new grant, and never fail the request over it.
        if (! $this->hasRequiredScopes($shop->scopes)
            && $this->cache->add(CacheKeys::scopeRecheck($shop->id), true, self::SCOPE_RECHECK_SECONDS)) {
            try {
                return $this->tokens->exchange($session->shopDomain, $session->raw);
            } catch (InvalidSessionTokenException|ShopifyApiException $e) {
                Monitor::expected($e, 'scope re-check', ['shop' => $shop->domain]);
            }
        }

        return $shop;
    }

    private function needsTokenExchange(Shop $shop): bool
    {
        return ! $shop->isInstalled()
            // Merchant has an active session: acquire a new token instead of refreshing.
            || $shop->accessTokenNeedsRefresh($this->refreshMargin);
    }

    private function install(SessionToken $session, ?Shop $existing): Shop
    {
        $isNewInstall = $existing === null || ! $existing->isInstalled();

        $extra = $isNewInstall ? [
            'installed_at' => now(),
            'uninstalled_at' => null,
            'sync_status' => SyncStatus::Pending,
            'sync_error' => null,
        ] : [];

        $shop = $this->tokens->exchange($session->shopDomain, $session->raw, $extra);

        if ($isNewInstall) {
            // One small GraphQL call so the first screen already knows currency + timezone.
            $this->shopService->refreshDetails($shop);
            ShopInstalled::dispatch($shop);
        }

        return $shop;
    }

    private function hasRequiredScopes(?string $granted): bool
    {
        $split = fn (?string $s) => array_filter(array_map('trim', explode(',', (string) $s)));
        $granted = $split($granted);

        // read_all_orders is not echoed back in the token's scope list; write_x implies read_x.
        foreach (array_diff($split($this->requiredScopes), ['read_all_orders']) as $scope) {
            $implied = str_starts_with($scope, 'read_') ? 'write_'.substr($scope, 5) : null;
            if (! in_array($scope, $granted, true) && ! in_array($implied, $granted, true)) {
                return false;
            }
        }

        return true;
    }
}
