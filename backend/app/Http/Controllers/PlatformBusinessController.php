<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\PlatformUsageActivity;
use App\Services\InviteBusinessUser;
use App\Services\PlatformAccess;
use App\Services\PlatformTenancy;
use App\Support\BusinessCurrencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformBusinessController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'lifecycle' => ['nullable', Rule::in(['onboarding', 'operating', 'closed'])], 'security' => ['nullable', Rule::in(['suspended', 'enabled'])], 'cover' => ['nullable', Rule::in(['trial', 'active', 'grace', 'restricted', 'legacy_review', 'exempt'])]]);
        $query = Business::query()->with('billingSubscriptions.plan')->withCount(['memberships' => fn ($q) => $q->where('active', true)])
            ->addSelect(['last_platform_activity' => PlatformUsageActivity::query()->selectRaw('MAX(last_seen_at)')->whereColumn('business_id', 'businesses.id')])
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('code', 'like', '%'.$term.'%')->orWhere('owner_contact_email', 'like', '%'.$term.'%')))
            ->when($filters['lifecycle'] ?? null, fn ($q, $v) => $q->where('lifecycle_status', $v))
            ->when(($filters['security'] ?? null) === 'suspended', fn ($q) => $q->whereNotNull('security_suspended_at'))
            ->when(($filters['security'] ?? null) === 'enabled', fn ($q) => $q->whereNull('security_suspended_at'));
        if (! empty($filters['cover'])) {
            $ids = [];
            (clone $query)->chunkById(200, function ($rows) use (&$ids, $filters) {
                foreach ($rows as $row) {
                    if (app(PlatformTenancy::class)->state($row)['state'] === $filters['cover']) {
                        $ids[] = $row->id;
                    }
                }
            });
            $query->whereIn('id', $ids);
        }
        $businesses = $query->latest('id')->workspacePaginate(25)->withQueryString();

        return view('platform.businesses', compact('businesses'));
    }

    public function store(Request $request, InviteBusinessUser $invites): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code'))), 'base_currency' => strtoupper(trim((string) $request->input('base_currency')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('businesses', 'code')],
            'country_code' => ['required', 'alpha', 'size:2'],
            'base_currency' => ['required', 'string', Rule::in(array_keys(app(BusinessCurrencies::class)->catalogue()))],
            'timezone' => ['required', 'timezone'],
            'default_qr_level' => ['required', Rule::in(['product', 'batch', 'carton'])],
            'owner_email' => ['required', 'email', 'max:255'],
        ]);

        $business = DB::transaction(fn () => Business::create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'country_code' => strtoupper($data['country_code']),
            'base_currency' => strtoupper($data['base_currency']),
            'timezone' => $data['timezone'],
            'default_qr_level' => $data['default_qr_level'], 'lifecycle_status' => 'onboarding', 'billing_mode' => 'trial', 'trial_ends_on' => today('Africa/Kampala')->addDays(14), 'owner_contact_email' => strtolower($data['owner_email']),
        ]));

        app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'business.created', 'Exporter registered and initial owner invitation requested.', [], ['name' => $business->name, 'code' => $business->code]);
        $invites->send($business, $data['owner_email'], 'owner', $request->user()->id);
        $message = config('mail.default') === 'log'
            ? "Created {$business->name}. The invitation was written to the development mail log, not delivered."
            : "Created {$business->name}. Review owner invitation delivery and acceptance on the business Access tab.";

        return redirect()->route('platform.businesses.index')->with('status', $message);
    }
}
