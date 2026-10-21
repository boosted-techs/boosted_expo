<?php

namespace App\Http\Middleware;

use App\Services\PrivilegedMfa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePrivilegedMfa
{
    public function __construct(private readonly PrivilegedMfa $mfa) {}

    public function handle(Request $request, Closure $next):Response
    {
        $user=$request->user();
        if(!$user || !$this->mfa->required($user))return $next($request);
        // Privilege/security state may have changed in an earlier request.
        // Refresh independently of a cached guard instance before granting access.
        $user->refresh();
        if($request->is('security/mfa') || $request->is('security/mfa/*')
            || $request->routeIs('logout'))return $next($request);

        if(!$user->mfa_enabled_at){
            return redirect()->route('security.mfa.setup');
        }
        if($request->session()->get('mfa_verified_user_id')!==$user->id){
            return redirect()->route('security.mfa.challenge');
        }
        return $next($request);
    }
}
