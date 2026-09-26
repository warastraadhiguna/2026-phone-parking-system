<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers for the admin web (master doc §33, XSS protection; Phase 11).
 *
 * - Content-Security-Policy: scripts, styles and connections only from this origin; images
 *   also from the configured map tile host. Inline styles are allowed (Leaflet and React set
 *   element styles), inline scripts are not.
 * - Strict-Transport-Security on HTTPS responses outside local development.
 *
 * Skipped while the Vite dev server is running (public/hot), whose HMR needs other origins.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! is_file(public_path('hot'))) {
            $response->headers->set('Content-Security-Policy', $this->policy());
        }
        if ($request->isSecure() && ! app()->isLocal()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }

    private function policy(): string
    {
        $images = ["'self'", 'data:', 'blob:'];
        $tiles = (string) config('reporting.map_tile_url');
        if ($tiles !== '') {
            $host = parse_url(str_replace(['{s}', '{z}', '{x}', '{y}', '{r}'], ['a', '0', '0', '0', ''], $tiles), PHP_URL_HOST);
            $scheme = parse_url($tiles, PHP_URL_SCHEME);
            if (is_string($host) && is_string($scheme)) {
                $images[] = "{$scheme}://".(str_contains($tiles, '{s}') ? '*.'.preg_replace('/^a\./', '', $host) : $host);
            }
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            'img-src '.implode(' ', $images),
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
