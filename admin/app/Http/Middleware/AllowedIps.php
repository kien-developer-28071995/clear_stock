<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/** Optional IP allowlist in front of everything (REPORT_ALLOWED_IPS); login is required either way. */
class AllowedIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('report.allowed_ips');
        // 404, not 403: an outsider learns nothing about what runs here.
        abort_if($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed), 404);

        return $next($request);
    }
}
