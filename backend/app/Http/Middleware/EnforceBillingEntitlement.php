<?php
namespace App\Http\Middleware;
use App\Models\BillingSubscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnforceBillingEntitlement {
 public function handle(Request $request, Closure $next): Response {
   $business=$request->attributes->get('activeBusiness');
   if(!$business || $request->routeIs('business.billing*')) return $next($request);
   $subscriptions=BillingSubscription::query()->where('business_id',$business->id)->where('status','!=','cancelled')->get();
   if($subscriptions->isEmpty()) return $next($request); // Legacy customers not migrated yet.
   $allowed=$subscriptions->contains(function($s) {
      if($s->billing_cycle==='one_time') return $s->status==='active';
      return $s->paid_through && $s->paid_through->copy()->addDays($s->grace_days)->gte(today());
   });
   if($allowed) return $next($request);
   if($request->user()?->is_platform_admin) return $next($request);
   return redirect()->route('business.billing')->with('status','Your subscription needs renewal. Your records remain safely stored. Please contact your business owner to arrange payment.');
 }
}
