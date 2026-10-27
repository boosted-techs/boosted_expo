<?php

namespace App\Http\Controllers;

use App\Models\PlatformAuditEvent;
use App\Models\PlatformCase;
use App\Models\PlatformSupportGrant;
use App\Models\User;
use App\Services\PlatformAccess;
use App\Services\PlatformTenancy;
use App\Services\TenantDataExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessPlatformController extends Controller
{
    public function status(Request $request, PlatformTenancy $tenancy): View
    {
        $business = $request->attributes->get('activeBusiness');

        return view('businesses.subscription-status', ['business' => $business, 'entitlement' => $tenancy->state($business), 'owners' => $business->memberships()->where('role', 'owner')->where('active', true)->with('user')->get(),
            'grants' => PlatformSupportGrant::query()->where('business_id', $business->id)->latest('id')->paginate(15),
            'cases' => PlatformCase::query()->where('business_id', $business->id)->latest('id')->paginate(15, ['*'], 'case_page'),
            'supportEvents' => PlatformAuditEvent::query()->where('business_id', $business->id)->where('action', 'like', 'support.%')->latest('id')->paginate(20, ['*'], 'support_page')]);
    }

    public function grant(Request $request, int $grant): RedirectResponse
    {
        abort_unless($request->attributes->get('membership')->role === 'owner', 403);
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'revoke'])], 'reason' => ['required', 'string', 'min:10', 'max:1500']]);
        $business = $request->attributes->get('activeBusiness');
        DB::transaction(function () use ($business, $grant, $request, $data) {
            $g = PlatformSupportGrant::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($grant);
            if ($data['action'] === 'approve') {
                abort_unless($g->status === 'requested' && $g->expires_at->isFuture(), 409, 'This request expired or was already decided.');
                $g->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            } else {
                $g->update(['status' => 'revoked', 'revoked_at' => now()]);
            }
            app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'support.'.$data['action'], $data['reason'], [], ['grant_id' => $g->id, 'scope' => $g->scope, 'platform_user_id' => $g->platform_user_id]);
        }, 3);

        return back()->with('status', 'Support access decision recorded. Expiry and revocation are checked on every support view.');
    }

    public function request(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->attributes->get('membership')->role, ['owner', 'admin'], true), 403);
        $data = $request->validate(['kind' => ['required', Rule::in(['support', 'export', 'offboarding'])], 'title' => ['required', 'string', 'max:150'], 'details' => ['required', 'string', 'min:20', 'max:4000']]);
        $business = $request->attributes->get('activeBusiness');
        $case = PlatformCase::create([...$data, 'business_id' => $business->id, 'created_by' => $request->user()->id, 'assigned_to' => User::query()->where('is_platform_admin', true)->where('platform_role', 'superadmin')->value('id'), 'due_at' => now()->addDays(2), 'priority' => 'normal']);
        app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'case.requested', $data['details'], [], ['case_id' => $case->id, 'kind' => $data['kind']]);

        return back()->with('status', 'Your request was recorded for platform follow-up. Export and offboarding requests do not delete or publish business records.');
    }

    public function export(Request $request, TenantDataExport $export): StreamedResponse
    {
        abort_unless($request->attributes->get('membership')->role === 'owner', 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1500']]);
        $business = $request->attributes->get('activeBusiness');
        app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'business.export', $data['reason'], [], ['scope' => 'Structured operational and accounting records; see file manifest for exclusions.']);

        return response()->streamDownload(fn () => $export->write($business),$business->code.'-business-records.json',['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
    }
}
