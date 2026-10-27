<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductionSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        // The actual TLS connection is verified by the web server or an explicitly
        // trusted reverse proxy. Never trust arbitrary client Forwarded headers.
        if (config('app.env') === 'production') {
            $url = (string) config('app.url');
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);

            if ($scheme !== 'https' || ! is_string($host) || $host === '') {
                abort(503, 'Production HTTPS settings are not configured.');
            }

            if (! $request->isSecure() || strcasecmp($request->getHost(), $host) !== 0) {
                $port = parse_url($url, PHP_URL_PORT);
                $base = 'https://'.$host.($port ? ':'.$port : '');

                return redirect()->to($base.$request->getRequestUri(), 307);
            }
        }

        $response = $next($request);
        $secure = $request->isSecure();

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', $request->is('buyer-order/*', 'customer-ordering/*', 'buyer-quotation/*')
            ? 'no-referrer' : 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self' data:",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
        ]).($secure && config('app.env') === 'production' ? '; upgrade-insecure-requests' : ''));

        if ($secure && config('app.env') === 'production') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if ($request->user() || $request->is('buyer-order/*', 'customer-ordering/*', 'buyer-quotation/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
