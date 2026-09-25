<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidSessionTokenException;
use App\Exceptions\ShopifyApiException;
use App\Services\ShopAuthService;
use App\Support\ShopContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            return new JsonResponse(['message' => 'Your session expired. Please reload the app.'], 401, [
                'X-Shopify-Retry-Invalid-Session-Request' => '1',
            ]);
        } catch (ShopifyApiException $e) {
            Log::error('Shopify authentication failed', ['error' => $e->getMessage()]);

            return new JsonResponse(['message' => 'Could not connect to Shopify. Please try again in a moment.'], 502);
        }

        $this->context->set($shop);

        return $next($request);
    }
}
