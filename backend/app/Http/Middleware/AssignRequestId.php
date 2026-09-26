<?php

namespace App\Http\Middleware;

use App\Support\RequestId\RequestId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reuses a well-formed incoming X-Request-Id (e.g. set by nginx or the Android app),
 * otherwise generates one. The ID is echoed back on every response.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(RequestId::HEADER);
        $requestId = RequestId::isValid($incoming) ? $incoming : RequestId::generate();

        RequestId::set($requestId);
        $request->headers->set(RequestId::HEADER, $requestId);

        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $requestId);

        return $response;
    }
}
