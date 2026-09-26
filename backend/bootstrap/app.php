<?php

use App\Exceptions\ApiErrorResponse;
use App\Exceptions\ApiException;
use App\Exceptions\InvalidSessionTokenException;
use App\Exceptions\PlanRequiredException;
use App\Http\Middleware\EmbeddedAppHeaders;
use App\Http\Middleware\VerifyShopifySessionToken;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        // Expected outcomes, not errors: never logged as errors (so never sent to Slack).
        $exceptions->dontReport([ApiException::class, PlanRequiredException::class, InvalidSessionTokenException::class]);

        // The API returns codes, never text: the embedded app translates them.
        $api = fn (Request $request) => $request->is('api/*');
        $exceptions->render(fn (ValidationException $e, Request $request) => $api($request) ? ApiErrorResponse::forValidation($e) : null);
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $api($request) ? ApiErrorResponse::forHttp($e) : null);
        $exceptions->render(function (Throwable $e, Request $request) use ($api) {
            if (! $api($request) || $e instanceof ApiException || $e instanceof PlanRequiredException || $e instanceof HttpResponseException) {
                return null;
            }
            if ($e instanceof AuthenticationException) {
                return ApiErrorResponse::make('unauthorized', 401);
            }

            return ApiErrorResponse::forServerError($e);
        });
    })->create();
