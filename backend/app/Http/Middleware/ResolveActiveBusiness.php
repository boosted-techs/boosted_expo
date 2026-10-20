<?php

namespace App\Http\Middleware;

use App\Models\BusinessMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActiveBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $memberships = BusinessMembership::query()
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->whereHas('business', fn ($query) => $query->where('is_active', true))
            ->with('business')
            ->get();

        if ($memberships->isEmpty()) {
            return $request->user()->is_platform_admin
                ? redirect()->route('platform.businesses.index')
                : redirect()->route('businesses.choose');
        }

        $selectedId = $request->session()->get('active_business_id');
        $membership = $selectedId
            ? $memberships->firstWhere('business_id', (int) $selectedId)
            : ($memberships->count() === 1 ? $memberships->first() : null);

        if (! $membership) {
            $request->session()->forget('active_business_id');
            return redirect()->route('businesses.choose');
        }

        $request->session()->put('active_business_id', $membership->business_id);
        $request->attributes->set('activeBusiness', $membership->business);
        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}