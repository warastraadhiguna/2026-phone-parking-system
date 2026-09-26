<?php

namespace App\Http\Middleware;

use App\Support\RequestContext\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the client IP available to domain code (audit trail) without an HTTP dependency.
 * The IP honours trusted proxies (TRUSTED_PROXIES).
 */
class CaptureRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestContext::setClientIp($request->ip());

        return $next($request);
    }
}
