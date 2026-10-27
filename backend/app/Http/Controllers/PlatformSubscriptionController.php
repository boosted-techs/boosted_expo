<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Business;
use App\Services\PlatformAccess;
use App\Services\PlatformBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformSubscriptionController extends Controller
{
    public function revise(Request $request, BillingPlan $plan): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'billing');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:1500'], 'monthly_price_ugx' => ['nullable', 'integer', 'min:0', 'max:999999999999'], 'quarterly_price_ugx' => ['nullable', 'integer', 'min:0', 'max:999999999999'], 'annual_price_ugx' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'modules_specified' => ['sometimes', 'boolean'], 'modules' => ['nullable', 'array'], 'modules.*' => ['string', 'distinct', Rule::in(PlatformAccess::MODULES)], 'seat_limit' => ['nullable', 'integer', 'between:1,100000'], 'storage_limit_bytes' => ['nullable', 'integer', 'min:0', 'max:1099511627776'], 'reason' => ['required', 'string', 'min:10', 'max:1500']]);
        unset($data['modules_specified']);
        DB::transaction(function () use ($plan, $data, $request) {
            $family = $plan->family_code ?? $plan->code;
            BillingPlan::query()->where(fn ($q) => $q->where('family_code', $family)->orWhere('code', $family))->orderBy('id')->lockForUpdate()->get(['id']);
            $locked = BillingPlan::query()->findOrFail($plan->id);
            $version = (int) BillingPlan::query()->where(fn ($q) => $q->where('family_code', $family)->orWhere('code', $family))->max('version') + 1;
            $new = BillingPlan::create([...collect($data)->except('reason')->all(), 'modules' => $data['modules'] ?? [], 'family_code' => $family, 'version' => $version, 'code' => substr($family, 0, 30).'-v'.$version, 'is_public' => $locked->is_public, 'is_active' => true]);
            BillingPlan::query()->where('family_code', $family)->where('id', '!=', $new->id)->update(['is_active' => false]);
            app(PlatformAccess::class)->audit(null, $request->user()->id, 'plan.revised', $data['reason'], $locked->toArray(), $new->toArray());
        }, 3);

        return back()->with('status', 'New plan version saved. Existing agreements and issued invoice terms stay unchanged until an agreed plan change takes effect.');
    }

    public function subscription(Request $request, BillingSubscription $subscription): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'billing');
        $data = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'cancel', 'schedule', 'contact'])], 'reason' => ['required', 'string', 'min:10', 'max:1500'],
            'billing_plan_id' => ['nullable', 'integer', Rule::exists('billing_plans', 'id')->where('is_active', true)], 'effective_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.today('Africa/Kampala')->toDateString()],
            'price_ugx' => ['nullable', 'integer', 'min:0', 'max:999999999999'], 'tax_rate' => ['nullable', 'integer', 'between:0,100'], 'billing_email' => ['nullable', 'email', 'max:255'], 'grace_days' => ['nullable', 'integer', 'between:0,30']]);
        DB::transaction(function () use ($subscription, $data, $request) {
            Business::query()->lockForUpdate()->findOrFail($subscription->business_id);
            $s = BillingSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $before = $s->toArray();
            if ($data['action'] === 'pause') {
                $s->paused_at = now();
                $s->status = 'paused';
            } elseif ($data['action'] === 'resume') {
                abort_if($s->cancelled_at, 409, 'Create a new agreement after cancellation.');
                $s->paused_at = null;
            } elseif ($data['action'] === 'cancel') {
                $s->cancelled_at = now();
                $s->status = 'cancelled';
                $s->scheduled_change = null;
                if ($s->business->billing_mode === 'trial') {
                    $s->business->update(['billing_mode' => 'subscribed']);
                }
            } elseif ($data['action'] === 'contact') {
                if (! empty($data['billing_email'])) {
                    $s->billing_email = strtolower($data['billing_email']);
                }
                if (isset($data['grace_days'])) {
                    $s->grace_days = $data['grace_days'];
                }
            } else {
                abort_if($s->cancelled_at || $s->paused_at, 409, 'Resume or create an agreement before scheduling a change.');
                if (! $s->next_invoice_on || empty($data['effective_on']) || empty($data['billing_plan_id']) || ! isset($data['price_ugx'],$data['tax_rate']) || $data['effective_on'] !== $s->next_invoice_on->toDateString()) {
                    throw ValidationException::withMessages(['effective_on' => 'Schedule changes for the next unissued invoice period. Specify the plan, agreed price and tax; mid-cycle proration is not automatic.']);
                }
                $plan = BillingPlan::query()->where('is_active', true)->findOrFail($data['billing_plan_id']);
                $s->scheduled_change = ['effective_on' => $data['effective_on'], 'terms' => app(PlatformBilling::class)->planTerms($plan), 'price_ugx' => $data['price_ugx'], 'tax_rate' => $data['tax_rate'], 'reason' => $data['reason']];
            }
            $s->save();
            if ($data['action'] === 'resume') {
                app(PlatformBilling::class)->reconcile($s);
            }
            app(PlatformAccess::class)->audit($s->business_id, $request->user()->id, 'subscription.'.$data['action'], $data['reason'], $before, $s->fresh()->toArray());
        }, 3);

        return back()->with('status', 'Agreement change recorded. Cancellation preserves business data; scheduled terms apply at their effective period.');
    }

    public function adjustment(Request $request, BillingInvoice $invoice, PlatformBilling $billing): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'billing');
        $data = $request->validate(['kind' => ['required', Rule::in(['credit', 'refund', 'void'])], 'amount_ugx' => ['required', 'integer', 'min:0', 'max:999999999999'], 'reason' => ['required', 'string', 'min:20', 'max:2000'], 'evidence_reference' => ['required', 'string', 'min:5', 'max:200'], 'request_key' => ['required', 'uuid']]);
        if ($data['kind'] !== 'void' && (int) $data['amount_ugx'] === 0) {
            throw ValidationException::withMessages(['amount_ugx' => 'Enter a positive credit or recorded refund.']);
        }
        $billing->adjust($invoice, $data, $request->user()->id);

        return back()->with('status', 'Billing correction recorded with preserved history. A recorded refund does not send money; it reopens the balance unless offset by a credit.');
    }
}
