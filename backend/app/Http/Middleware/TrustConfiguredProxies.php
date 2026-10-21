<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustConfiguredProxies
{
    public function handle(Request $request, Closure $next): Response
    {
        // This middleware executes AFTER Laravel loads cached configuration.
        // Never trust arbitrary public Forwarded headers or request-supplied
        // proxy addresses; only the actual infrastructure CIDRs configured
        // by a deployment operator may describe the original HTTPS request.
        $proxies=config('expo_operations.trusted_proxies',[]);
        $proxies=is_array($proxies)?array_values(array_filter($proxies,
            fn($value)=>is_string($value) && $value!=='' && $value!=='*')):[];
        Request::setTrustedProxies($proxies,
            Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PROTO |
            Request::HEADER_X_FORWARDED_PORT);

        return $next($request);
    }
}
