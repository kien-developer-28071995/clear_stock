<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the Horizon dashboard with HTTP basic auth outside the local env
 * (the app has no user accounts). Disabled entirely when no credentials are set.
 */
class HorizonBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        $user = (string) config('horizon.basic_auth.user');
        $password = (string) config('horizon.basic_auth.password');

        $ok = $user !== '' && $password !== ''
            && hash_equals($user, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword());

        if (! $ok) {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="Horizon"']);
        }

        return $next($request);
    }
}
