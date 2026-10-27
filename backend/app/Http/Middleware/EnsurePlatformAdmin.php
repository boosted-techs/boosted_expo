<?php

namespace App\Http\Middleware;

use App\Services\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next, string $area = 'auto'): Response
    {
        $request->user()?->refresh();
        if ($area === 'auto') {
            $name = (string) $request->route()?->getName();
            $area = str_starts_with($name, 'platform.billing.request') ? 'business' : (str_starts_with($name, 'platform.billing') ? 'billing' : ($name === 'platform.businesses.store' ? 'business' : 'overview'));
        }
        app(PlatformAccess::class)->check($request->user(), $area);

        return $next($request);
    }
}
