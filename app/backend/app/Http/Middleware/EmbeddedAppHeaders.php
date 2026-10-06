<?php

namespace App\Http\Middleware;

use App\Support\ShopDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only allow the Shopify admin (and the shop's own domain) to frame the app.
 * Required for embedded apps: https://shopify.dev/docs/apps/build/security/set-up-iframe-protection
 */
class EmbeddedAppHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $shop = ShopDomain::normalize($request->query('shop'));
        $ancestors = $shop
            ? "https://{$shop} https://admin.shopify.com"
            : "'none'";

        $response->headers->set('Content-Security-Policy', "frame-ancestors {$ancestors};");

        return $response;
    }
}
