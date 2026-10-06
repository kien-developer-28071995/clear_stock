<?php

namespace App\Http\Middleware;

use App\Support\FeatureUsage;
use App\Support\ShopContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `usage:<feature>`: counts a successful request to a feature that stores nothing, once the
 * response is on its way (see FeatureUsage). Refused requests (plan, switch, validation) don't count.
 */
class RecordFeatureUsage
{
    public function __construct(private readonly ShopContext $context) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $response = $next($request);
        if ($response->isSuccessful() && $this->context->has()) {
            FeatureUsage::record($this->context->shop(), $feature);
        }

        return $response;
    }
}
