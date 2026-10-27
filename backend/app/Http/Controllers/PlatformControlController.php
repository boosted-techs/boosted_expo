<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\BusinessMembership;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformDelivery;
use App\Models\PlatformLimitOverride;
use App\Models\PlatformSupportGrant;
use App\Models\PlatformUsageActivity;
use App\Models\PlatformUsageSnapshot;
use App\Services\InviteBusinessUser;
use App\Services\PlatformAccess;
use App\Services\PlatformDashboard;
use App\Services\PlatformTenancy;
use App\Services\PlatformUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformControlController extends Controller
{
    public function overview(Request $request, PlatformDashboard $dashboard): View
    {
        return view('platform.overview', $dashboard->overview($request));
    }

    public function show(Request $request, Business $business, PlatformTenancy $tenancy, PlatformUsage $usage): View
    {
        $tab = $request->validate(['tab' => ['nullable', Rule::in(['overview', 'subscription', 'usage', 'access', 'health', 'history'])]])['tab'] ?? 'overview';
        $access = app(PlatformAccess::class);
        if (in_array($tab, ['subscription'], true)) {
            $access->check($request->user(), 'billing');
        }
        if ($tab === 'history') {
            $access->check($request->user(), 'audit');
        }
        if ($tab === 'access') {
            $access->check($request->user(), 'business');
        }
        $state = $tenancy->state($business);
        $data = ['business' => $business, 'tab' => $tab, 'state' => $state,
            'owners' => $business->memberships()->where('role', 'owner')->with('user')->get(),
            'lastActivity' => PlatformUsageActivity::query()->where('business_id', $business->id)->max('last_seen_at')];
        if ($tab === 'usage' || $tab === 'overview') {
            $data['allowances'] = ['seat_limit' => $tenancy->allowance($business, 'seat_limit'), 'storage_limit_bytes' => $tenancy->allowance($business, 'storage_limit_bytes')];
            $data['snapshots'] = PlatformUsageSnapshot::query()->where('business_id', $business->id)->latest('measured_at')->limit(10)->get();
            $data['usage'] = $usage->counters($business, today('Africa/Kampala')->startOfMonth()->toDateString(), today('Africa/Kampala')->toDateString());
        }
        if ($tab === 'overview') {
            $data['milestones'] = [
                'Owner invitation accepted' => BusinessInvitation::query()->where('business_id', $business->id)->where('role', 'owner')->min('accepted_at'),
                'First product recorded' => DB::table('products')->where('business_id', $business->id)->min('created_at'),
                'First confirmed order' => DB::table('sales_orders')->where('business_id', $business->id)->min('confirmed_at'),
                'First dispatch recorded' => DB::table('export_shipments')->where('business_id', $business->id)->min('actual_departure_at'),
            ];
        }
        if ($tab === 'subscription') {
            $data['subscriptions'] = BillingSubscription::query()->where('business_id', $business->id)->with('plan')->latest('id')->paginate(15);
            $data['invoices'] = BillingInvoice::query()->where('business_id', $business->id)->latest('id')->paginate(15, ['*'], 'invoice_page');
            $data['plans'] = BillingPlan::query()->where('is_active', true)->orderBy('name')->get();
        }
        if ($tab === 'access') {
            $data['invitations'] = BusinessInvitation::query()->where('business_id', $business->id)->latest('id')->paginate(20);
            $data['deliveries'] = PlatformDelivery::query()->where('business_id', $business->id)->where('kind', 'invitation')->whereIn('source_id', $data['invitations']->modelKeys())->latest('id')->get(['id', 'source_id', 'status', 'attempts', 'attempted_at', 'failure_note']);
        }
        if ($tab === 'health') {
            $data += app(PlatformDashboard::class)->health($business->id);
        }
        if ($tab === 'history') {
            $data['events'] = PlatformAuditEvent::query()->where('business_id', $business->id)->latest('id')->paginate(30);
        }
        $data['supportRequests'] = PlatformSupportGrant::query()->where('business_id', $business->id)->latest('id')->limit(10)->get();

        return view('platform.business', $data);
    }

    public function update(Request $request, Business $business, PlatformAccess $access): RedirectResponse
    {
        $access->check($request->user(), 'business');
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:150'], 'country_code' => ['required', 'alpha', 'size:2'], 'timezone' => ['required', 'timezone'], 'owner_contact_email' => ['nullable', 'email', 'max:255'], 'reason' => ['required', 'string', 'min:10', 'max:1500']]);
        DB::transaction(function () use ($business, $data, $request, $access) {
            $b = Business::query()->lockForUpdate()->findOrFail($business->id);
            $before = $b->only(['name', 'country_code', 'timezone', 'owner_contact_email']);
            $b->update(collect($data)->except('reason')->all());
            $access->audit($b->id, $request->user()->id, 'business.profile', $data['reason'], $before, $b->only(array_keys($before)));
        }, 3);

        return back()->with('status', 'Business profile updated. Historical invoices and documents retain their issued details.');
    }

    public function lifecycle(Request $request, Business $business, PlatformTenancy $tenancy): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'business');
        $data = $request->validate(['action' => ['required', Rule::in(['suspend', 'reinstate', 'close', 'reopen', 'complete_onboarding'])], 'reason' => ['required', 'string', 'min:10', 'max:1500'], 'confirmed' => ['required', 'accepted']]);
        $tenancy->lifecycle($business, $data['action'], $data['reason'], $request->user()->id);

        return back()->with('status', 'Business lifecycle change recorded. Security suspension and billing cover are evaluated separately.');
    }

    public function invitation(Request $request, Business $business, InviteBusinessUser $invites): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'business');
        $data = $request->validate(['action' => ['required', Rule::in(['invite_owner', 'transfer_owner', 'resend', 'revoke'])], 'email' => ['nullable', 'email', 'max:255'], 'invitation_id' => ['nullable', 'integer'], 'membership_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'min:20', 'max:1500'], 'identity_verified' => ['required', 'accepted']]);
        $old = null;
        $replacement = null;
        if (in_array($data['action'], ['resend', 'revoke'], true)) {
            $old = BusinessInvitation::query()->where('business_id', $business->id)->findOrFail($data['invitation_id'] ?? 0);
            abort_if($old->accepted_at, 409, 'This invitation has already been accepted.');
        }
        if ($data['action'] === 'revoke') {
            $old->update(['revoked_at' => now()]);
        } else {
            $email = $old?->email ?? ($data['email'] ?? null);
            if (! $email) {
                throw ValidationException::withMessages(['email' => 'Provide the verified owner email address.']);
            }
            if ($data['action'] === 'transfer_owner') {
                $replacement = BusinessMembership::query()->where('business_id', $business->id)->where('role', 'owner')->where('active', true)->findOrFail($data['membership_id'] ?? 0);
            } elseif ($data['action'] === 'invite_owner' && $business->memberships()->where('role', 'owner')->where('active', true)->exists()) {
                throw ValidationException::withMessages(['action' => 'This business already has an owner. Use the explicit owner-transfer process.']);
            }
            $invites->send($business, $email, $old?->role ?? 'owner', $request->user()->id, $replacement ? 'owner_transfer' : ($old?->purpose ?? 'owner_invite'), $replacement?->id ?? $old?->replaces_membership_id);
        }
        app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'owner.'.$data['action'], $data['reason'], [], ['invitation_id' => $old?->id, 'replacement_membership_id' => $replacement?->id, 'email' => $data['email'] ?? $old?->email]);

        return back()->with('status', 'Owner invitation action recorded. Review its delivery status; no password or device secret is exposed.');
    }

    public function billingAccess(Request $request, Business $business): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'billing');
        $data = $request->validate(['mode' => ['required', Rule::in(['trial', 'exempt', 'subscribed'])], 'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.today('Africa/Kampala')->toDateString(), 'before_or_equal:'.today('Africa/Kampala')->addYear()->toDateString()], 'reason' => ['required', 'string', 'min:10', 'max:1500']]);
        if ($data['mode'] !== 'subscribed' && empty($data['until'])) {
            throw ValidationException::withMessages(['until' => 'A trial or exemption must have an end date.']);
        }
        DB::transaction(function () use ($business, $data, $request) {
            $b = Business::query()->lockForUpdate()->findOrFail($business->id);
            $before = $b->only(['billing_mode', 'trial_ends_on', 'billing_exempt_until']);
            $b->billing_mode = $data['mode'];
            if ($data['mode'] === 'trial') {
                $b->trial_ends_on = $data['until'];
            }
            if ($data['mode'] === 'exempt') {
                $b->billing_exempt_until = $data['until'];
            }
            $b->save();
            app(PlatformAccess::class)->audit($b->id, $request->user()->id, 'billing.access', $data['reason'], $before, $b->only(array_keys($before)));
        }, 3);

        return back()->with('status', 'Dated billing access updated. Security suspension still applies.');
    }

    public function allowance(Request $request, Business $business): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'billing');
        $data = $request->validate(['metric' => ['required', Rule::in(['seat_limit', 'storage_limit_bytes'])], 'allowance' => ['required', 'integer', 'min:0', 'max:1099511627776'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:'.today('Africa/Kampala')->addYear()->toDateString()], 'reason' => ['required', 'string', 'min:10', 'max:1500']]);
        PlatformLimitOverride::create([...$data, 'business_id' => $business->id, 'created_by' => $request->user()->id]);
        app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'usage.override', $data['reason'], [], $data);

        return back()->with('status', 'Dated allowance override recorded. Once expired, the agreed plan allowance applies again.');
    }

    public function snapshot(Request $request, Business $business, PlatformUsage $usage): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'health');
        $usage->snapshot($business, today('Africa/Kampala')->startOfMonth()->toDateString(), today('Africa/Kampala')->toDateString());

        return back()->with('status', 'Usage snapshot reconciled from source records. Repeating it updates the same period, without adding billable events.');
    }
}
