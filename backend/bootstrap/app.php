<?php

use App\Http\Middleware\EmbeddedAppHeaders;
use App\Http\Middleware\VerifyShopifySessionToken;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS terminates at the tunnel / load balancer.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'shopify.session' => VerifyShopifySessionToken::class,
            'embedded.headers' => EmbeddedAppHeaders::class,
            'shopify.webhook' => VerifyShopifyWebhook::class,
        ]);

        // The app never uses cookies: drop session/cookie/CSRF middleware from the web group.
        $middleware->web(remove: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
