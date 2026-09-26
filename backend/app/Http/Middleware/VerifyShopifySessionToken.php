<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiErrorResponse;
use App\Exceptions\InvalidSessionTokenException;
use App\Exceptions\ShopifyApiException;
use App\Services\ShopAuthService;
use App\Support\Monitor;
use App\Support\ShopContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates API calls from the embedded app with the App Bridge session
 * token (Authorization: Bearer <id token>). No cookies involved.
 */
class VerifyShopifySessionToken
{
    public function __construct(
        private readonly ShopAuthService $auth,
        private readonly ShopContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $shop = $this->auth->authenticate($request->bearerToken());
        } catch (InvalidSessionTokenException $e) {
            // Tells App Bridge to fetch a fresh session token and retry once.
            return ApiErrorResponse::make('session_expired', 401, [], [
                'X-Shopify-Retry-Invalid-Session-Request' => '1',
            ]);
        } catch (ShopifyApiException $e) {
            Monitor::caught($e, 'session authentication');

            return ApiErrorResponse::make('shopify_unavailable', 502);
        }

        $this->context->set($shop);
        Context::add('shop', $shop->domain); // shown on error alerts

        return $next($request);
    }
}
