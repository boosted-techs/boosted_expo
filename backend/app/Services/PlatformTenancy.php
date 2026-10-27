<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\Business;
use App\Models\PlatformLimitOverride;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformTenancy
{
    public function subscription(Business $business): ?BillingSubscription
    {
        if ($business->relationLoaded('billingSubscriptions')) {
            return $business->billingSubscriptions->first();
        }

        return BillingSubscription::query()->where('business_id', $business->id)->whereNull('cancelled_at')->where('status', '!=', 'cancelled')->with('plan')->latest('id')->first();
    }

    public function state(Business $business): array
    {
        $today = today('Africa/Kampala');
        $subscription = $this->subscription($business);
        $terms = $subscription?->terms_snapshot ?? ($subscription ? app(PlatformBilling::class)->planTerms($subscription->plan) : []);
        if ($subscription?->scheduled_change && $subscription->scheduled_change['effective_on'] <= today('Africa/Kampala')->toDateString()) {
            $terms = $subscription->scheduled_change['terms'];
        }
        $state = 'restricted';
        $until = null;
        if ($business->billing_mode === 'exempt' && $business->billing_exempt_until?->gte($today)) {
            $state = 'exempt';
            $until = $business->billing_exempt_until;
        } elseif ($subscription && ! $subscription->paused_at && $subscription->starts_on->toDateString() <= $today->toDateString()) {
            if ($subscription->billing_cycle === 'one_time' && $subscription->status === 'active') {
                $state = 'active';
            } elseif ($subscription->paid_through?->gte($today)) {
                $state = 'active';
                $until = $subscription->paid_through;
            } elseif ($subscription->paid_through?->copy()->addDays($subscription->grace_days)->gte($today)) {
                $state = 'grace';
                $until = $subscription->paid_through->copy()->addDays($subscription->grace_days);
            }
        }
        if ($state === 'restricted' && (! $subscription || $subscription->status === 'pending') && $business->billing_mode === 'trial' && $business->trial_ends_on?->gte($today)) {
            $state = 'trial';
            $until = $business->trial_ends_on;
        }
        if ($state === 'restricted' && $business->billing_mode === 'legacy_review') {
            $until = $business->billing_review_due_on ?? $business->created_at->copy()->addDays(30)->startOfDay();
            if ($until->gte($today)) {
                $state = 'legacy_review';
            }
        }

        return ['state' => $state, 'can_write' => $state !== 'restricted' && $business->is_active, 'until' => $until, 'subscription' => $subscription, 'terms' => $terms];
    }

    public function allowance(Business $business, string $metric): ?int
    {
        $override = PlatformLimitOverride::query()->where('business_id', $business->id)->where('metric', $metric)
            ->whereDate('starts_on', '<=', today('Africa/Kampala'))->whereDate('ends_on', '>=', today('Africa/Kampala'))->latest('id')->first();
        if ($override) {
            return (int) $override->allowance;
        }
        $terms = $this->state($business)['terms'];
        $value = $terms[$metric] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function lifecycle(Business $business, string $action, string $reason, int $actor): void
    {
        DB::transaction(function () use ($business, $action, $reason, $actor) {
            $b = Business::query()->lockForUpdate()->findOrFail($business->id);
            $before = $b->only(['is_active', 'lifecycle_status', 'security_suspended_at']);
            if ($action === 'suspend') {
                $b->security_suspended_at = now();
            } elseif ($action === 'reinstate') {
                $b->security_suspended_at = null;
            } elseif ($action === 'close') {
                $b->lifecycle_status = 'closed';
            } elseif ($action === 'reopen') {
                $b->lifecycle_status = 'operating';
            } elseif ($action === 'complete_onboarding') {
                $b->lifecycle_status = 'operating';
            } else {
                throw ValidationException::withMessages(['action' => 'Choose a supported business lifecycle action.']);
            }
            $b->is_active = $b->lifecycle_status !== 'closed' && ! $b->security_suspended_at;
            $b->save();
            app(PlatformAccess::class)->audit($b->id, $actor, 'business.'.$action, $reason, $before, $b->only(array_keys($before)));
        }, 3);
    }

    public function moduleFor(string $route): string
    {
        foreach (['sales-' => 'sales', 'sales.' => 'sales', 'commercial-invoices.' => 'sales', 'customers.' => 'sales',
            'supplier' => 'procurement', 'supply-' => 'procurement', 'purchases.' => 'procurement', 'receiving.' => 'procurement', 'stock.' => 'procurement',
            'packing.' => 'packing', 'cartons.' => 'packing', 'labels.' => 'packing', 'shipments.' => 'exports', 'export-documents.' => 'exports', 'document-templates.' => 'exports',
            'finance.' => 'finance', 'staff.' => 'people', 'team.' => 'people', 'integrations.' => 'integrations'] as $prefix => $module) {
            if (str_starts_with($route, $prefix)) {
                return $module;
            }
        }

        return 'core';
    }

    public function assertModuleWrite(Business $business, string $module): void
    {
        $state = $this->state($business);
        abort_unless($state['can_write'], 402, 'New work is paused. Contact the exporting business owner to review account access.');
        $modules = $state['terms']['modules'] ?? null;
        abort_if($modules !== null && $module !== 'core' && ! in_array($module,$modules,true), 403, 'New work in this module is not included in the current agreement.');
    }
}
