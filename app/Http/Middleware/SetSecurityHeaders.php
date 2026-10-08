<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response headers for every route. HSTS is sent only over HTTPS
 * (browsers ignore it otherwise), which in production is every request
 * since Caddy redirects plain HTTP.
 */
class SetSecurityHeaders
{
    /**
     * Content Security Policy for the only HTML the app serves: the Scramble
     * docs UI, which loads Stoplight Elements from unpkg and bootstraps it
     * with inline script and style. 'unsafe-inline' is unavoidable for that
     * widget; the policy still pins every origin and forbids framing,
     * plugins and <base> hijacking.
     */
    private const array CONTENT_SECURITY_POLICY = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://unpkg.com",
        "style-src 'self' 'unsafe-inline' https://unpkg.com",
        "font-src 'self' data: https://unpkg.com",
        "img-src 'self' data: https:",
        "connect-src 'self'",
        "worker-src 'self' blob:",
        "object-src 'none'",
        "base-uri 'self'",
        "frame-ancestors 'none'",
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // PHP announces its exact version here by default, which only helps
        // an attacker match the server to a CVE. expose_php adds it at the
        // SAPI level, not to the response's header bag, so header_remove is
        // what actually drops it; the bag is cleared too for good measure.
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Content-Security-Policy', implode('; ', self::CONTENT_SECURITY_POLICY));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
