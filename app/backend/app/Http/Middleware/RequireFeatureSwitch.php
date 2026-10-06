<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:<switch>`: the route belongs to a feature every plan has, so only the app-wide
 * switch (config/features.php) decides. Off = 404 `feature_disabled`, like plan features.
 */
class RequireFeatureSwitch
{
    public function handle(Request $request, Closure $next, string $switch): Response
    {
        if (! Features::on($switch)) {
            throw ApiException::featureDisabled($switch);
        }

        return $next($request);
    }
}
