<?php

namespace App\Providers;

use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\ShopAuthService;
use App\Services\Shopify\AdminApiClient;
use App\Services\Shopify\SessionTokenValidator;
use App\Services\Shopify\ShopifyOAuthClient;
use App\Services\Shopify\ShopTokenService;
use App\Services\ShopService;
use App\Support\ShopContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The shop authenticated for the current request (set by VerifyShopifySessionToken).
        $this->app->scoped(ShopContext::class);

        $this->app->singleton(SessionTokenValidator::class, fn () => new SessionTokenValidator(
            (string) config('shopify.api_key'),
            (string) config('shopify.api_secret'),
            (int) config('shopify.jwt_leeway'),
        ));

        $this->app->singleton(ShopifyOAuthClient::class, fn ($app) => new ShopifyOAuthClient(
            $app->make(Http::class),
            (string) config('shopify.api_key'),
            (string) config('shopify.api_secret'),
            (int) config('shopify.http_timeout'),
        ));

        $this->app->singleton(ShopTokenService::class, fn ($app) => new ShopTokenService(
            $app->make(ShopifyOAuthClient::class),
            $app->make(ShopRepositoryInterface::class),
            $app->make(Cache::class),
            (int) config('shopify.token_refresh_margin'),
        ));

        $this->app->singleton(AdminApiClient::class, fn ($app) => new AdminApiClient(
            $app->make(Http::class),
            $app->make(ShopTokenService::class),
            (string) config('shopify.api_version'),
            (int) config('shopify.http_timeout'),
        ));

        $this->app->singleton(ShopAuthService::class, fn ($app) => new ShopAuthService(
            $app->make(SessionTokenValidator::class),
            $app->make(ShopRepositoryInterface::class),
            $app->make(ShopTokenService::class),
            $app->make(ShopService::class),
            (string) config('shopify.scopes'),
            (int) config('shopify.token_refresh_margin'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
