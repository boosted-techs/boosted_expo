<?php

namespace App\Http\Middleware;

use App\Services\PlatformTenancy;
use App\Services\PlatformUsage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceBillingEntitlement
{
    public function handle(Request $request, Closure $next): Response
    {
        $business = $request->attributes->get('activeBusiness');
        if (! $business) {
            return $next($request);
        }
        $state = app(PlatformTenancy::class)->state($business);
        $request->attributes->set('platformEntitlement', $state);
        $route = (string) $request->route()?->getName();
        $recovery = $request->routeIs('business.billing*', 'business.subscription-status', 'business.platform*', 'labels.history.download');
        $recovery = $recovery || ($request->routeIs('team.members.update') && $request->has('active') && ! $request->boolean('active'));
        if (! $request->isMethodSafe() && ! $recovery) {
            if (! $state['can_write']) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Subscription renewal is required. Existing records remain readable. Contact the business owner.'], 402);
                }

                return redirect()->route('business.subscription-status')->with('status', 'Business work is read-only while subscription renewal is due. Your records remain available. Contact your owner.');
            }
            $module = app(PlatformTenancy::class)->moduleFor($route);
            $modules = $state['terms']['modules'] ?? null;
            if ($modules !== null && $module !== 'core' && ! in_array($module, $modules, true)) {
                abort(403, 'This agreement does not include new work in this module. Existing records remain readable. Ask the owner to review the plan.');
            }
        }
        $response = $next($request);
        if ($response->getStatusCode() < 400 && ! $recovery && ! $request->user()->is_platform_admin) {
            app(PlatformUsage::class)->record($business, $request->user()->id);
        }

        return $response;
    }
}
