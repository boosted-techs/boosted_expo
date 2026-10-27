<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformCase;
use App\Models\PlatformDelivery;
use App\Models\PlatformHealthEvidence;
use App\Models\PlatformSupportGrant;
use App\Models\User;
use App\Services\PlatformAccess;
use App\Services\PlatformDashboard;
use App\Services\PlatformMail;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformOperationsController extends Controller
{
    private function normalizeDates(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            $value = $request->input($field);
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value)) {
                try {
                    $request->merge([$field => Carbon::parse($value, 'Africa/Kampala')->utc()->toIso8601String()]);
                } catch (\Throwable) {
                }
            }
        }
    }

    public function index(Request $request): View
    {
        app(PlatformAccess::class)->check($request->user(), 'cases');
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved'])], 'business_id' => ['nullable', 'integer']]);

        return view('platform.operations', [
            'cases' => PlatformCase::query()->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($data['business_id'] ?? null, fn ($q, $v) => $q->where('business_id', $v))->orderBy('due_at')->paginate(25),
            'grants' => PlatformSupportGrant::query()->when($request->user()->platform_role !== 'superadmin', fn ($q) => $q->where('platform_user_id', $request->user()->id))->latest('id')->paginate(15, ['*'], 'grant_page'),
            'events' => app(PlatformAccess::class)->allows($request->user(), 'audit') ? PlatformAuditEvent::query()->latest('id')->paginate(25, ['*'], 'audit_page') : collect(),
            'operators' => User::query()->where('is_platform_admin', true)->orderBy('name')->get(['id', 'name', 'platform_role']),
            'businesses' => Business::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function case(Request $request, ?int $case = null): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'cases');
        $this->normalizeDates($request, ['due_at']);
        $data = $request->validate(['business_id' => ['nullable', 'integer', Rule::exists('businesses', 'id')], 'kind' => ['required', Rule::in(['support', 'incident', 'renewal', 'export', 'offboarding'])],
            'title' => ['required', 'string', 'max:150'], 'details' => ['required', 'string', 'min:10', 'max:4000'], 'status' => ['required', Rule::in(['open', 'acknowledged', 'resolved'])], 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')->where('is_platform_admin', true)], 'due_at' => ['required', 'date'], 'resolution' => ['nullable', 'string', 'min:10', 'max:2000']]);
        if ($data['status'] === 'resolved' && empty($data['resolution'])) {
            throw ValidationException::withMessages(['resolution' => 'Record what resolved this case.']);
        }
        $data['due_at'] = Carbon::parse($data['due_at'], 'Africa/Kampala')->utc();
        DB::transaction(function () use ($case, $data, $request) {
            $record = $case ? PlatformCase::query()->lockForUpdate()->findOrFail($case) : new PlatformCase(['created_by' => $request->user()->id]);
            $before = $record->exists ? $record->only(['status', 'assigned_to', 'due_at', 'resolution']) : [];
            $record->fill($data)->save();
            app(PlatformAccess::class)->audit($record->business_id, $request->user()->id, 'case.updated', $data['details'], $before, ['case_id' => $record->id, 'status' => $record->status, 'assigned_to' => $record->assigned_to]);
        }, 3);

        return back()->with('status', 'Action ownership, due time and evidence recorded.');
    }

    public function requestSupport(Request $request, Business $business): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'support');
        $this->normalizeDates($request, ['expires_at']);
        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:2000'], 'expires_at' => ['required', 'date', 'after:now', 'before_or_equal:'.now()->addHours(4)->toIso8601String()]]);
        abort_unless($business->is_active, 409, 'Support data access needs an active business and owner approval.');
        $grant = PlatformSupportGrant::create(['business_id' => $business->id, 'platform_user_id' => $request->user()->id, 'reason' => $data['reason'], 'expires_at' => Carbon::parse($data['expires_at'], 'Africa/Kampala')->utc()]);
        app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'support.requested', $data['reason'], [], ['grant_id' => $grant->id]);

        return back()->with('status', 'Support access requested. A current business owner must approve it in their workspace; requesting it does not grant access.');
    }

    public function support(Request $request, int $grant): View
    {
        app(PlatformAccess::class)->check($request->user(), 'support');
        $grant = PlatformSupportGrant::query()->where('platform_user_id', $request->user()->id)->where('status', 'approved')->whereNull('revoked_at')->where('expires_at', '>', now())->findOrFail($grant);
        $business = Business::query()->where('is_active', true)->findOrFail($grant->business_id);
        app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'support.viewed', $grant->reason, [], ['grant_id' => $grant->id, 'scope' => 'operations_read']);

        return view('platform.support', [
            'business' => $business, 'grant' => $grant,
            'orders' => DB::table('sales_orders')->where('business_id', $business->id)->latest('id')->paginate(20, ['reference', 'status', 'confirmed_at']),
            'shipments' => DB::table('export_shipments')->where('business_id', $business->id)->latest('id')->paginate(20, ['reference', 'status', 'scheduled_departure_at'], 'shipment_page'),
            'printJobs' => DB::table('label_print_jobs')->where('business_id', $business->id)->latest('id')->paginate(20, ['id', 'status', 'attempts', 'failure_note', 'created_at'], 'print_page'),
        ]);
    }

    public function health(Request $request, PlatformDashboard $dashboard): View
    {
        app(PlatformAccess::class)->check($request->user(), 'health');

        return view('platform.health', [...$dashboard->health(null), 'evidence' => PlatformHealthEvidence::query()->latest('id')->paginate(25),
            'businesses' => Business::query()->orderBy('name')->get(['id', 'name']),
            'deliveries' => PlatformDelivery::query()->whereIn('status', ['failed', 'uncertain', 'pending', 'processing'])->latest('id')->paginate(20, ['*'], 'delivery_page')]);
    }

    public function evidence(Request $request): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'health');
        $this->normalizeDates($request, ['observed_at', 'valid_until']);
        $data = $request->validate(['business_id' => ['nullable', 'integer', Rule::exists('businesses', 'id')], 'metric' => ['required', Rule::in(['backup', 'restore', 'worker', 'storage', 'errors', 'release', 'service_cost'])],
            'status' => ['required', Rule::in(['ok', 'warning', 'failed'])], 'evidence' => ['required', 'string', 'min:20', 'max:3000'], 'observed_at' => ['required', 'date', 'before_or_equal:now'], 'valid_until' => ['required', 'date', 'after:observed_at'],
            'value_ugx' => ['nullable', 'integer', 'min:0', 'max:999999999999'], 'period_start' => ['nullable', 'date_format:Y-m-d'], 'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_start']]);
        foreach (['observed_at', 'valid_until'] as $key) {
            $data[$key] = Carbon::parse($data[$key], 'Africa/Kampala')->utc();
        }
        if ($data['metric'] === 'service_cost' && (! isset($data['value_ugx']) || empty($data['period_start']) || empty($data['period_end']))) {
            throw ValidationException::withMessages(['value_ugx' => 'Cost evidence needs an amount, allocation basis and exact reporting period.']);
        }
        $hours = ['backup' => 30, 'worker' => 1, 'errors' => 24, 'storage' => 24, 'restore' => 2160, 'release' => 720, 'service_cost' => 1488][$data['metric']];
        if ($data['valid_until']->gt($data['observed_at']->copy()->addHours($hours))) {
            throw ValidationException::withMessages(['valid_until' => 'This indicator cannot remain current that long. Use a shorter interval and record a fresh observation.']);
        }
        $record = PlatformHealthEvidence::create([...$data, 'recorded_by' => $request->user()->id]);
        app(PlatformAccess::class)->audit($record->business_id, $request->user()->id, 'health.evidence', $data['evidence'], [], ['evidence_id' => $record->id, 'metric' => $record->metric, 'status' => $record->status]);

        return back()->with('status', 'Health evidence recorded with its source time and expiry. An absent or stale observation remains unknown.');
    }

    public function retry(Request $request, int $delivery, PlatformMail $mail): RedirectResponse
    {
        $d = PlatformDelivery::query()->findOrFail($delivery);
        app(PlatformAccess::class)->check($request->user(), $d->kind === 'invitation' ? 'business' : 'billing');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        DB::transaction(function () use ($d) {
            $d = PlatformDelivery::query()->lockForUpdate()->findOrFail($d->id);
            abort_unless(in_array($d->status, ['failed', 'uncertain', 'pending'], true) || ($d->status === 'processing' && $d->lease_until?->isPast()), 409, 'This delivery is not eligible for retry.');
            $d->update(['status' => 'failed', 'lease_until' => null]);
        }, 3);
        $mail->deliver($d->fresh(), true);
        app(PlatformAccess::class)->audit($d->business_id, $request->user()->id, 'delivery.retry', $data['reason'], [], ['delivery_id' => $d->id]);

        return back()->with('status', 'Delivery retry attempted. Review its recorded result.');
    }

    public function settings(Request $request): View
    {
        app(PlatformAccess::class)->check($request->user(), 'settings');

        return view('platform.settings', ['operators' => User::query()->where('is_platform_admin', true)->orderBy('name')->get()]);
    }

    public function role(Request $request): RedirectResponse
    {
        app(PlatformAccess::class)->check($request->user(), 'settings');
        $data = $request->validate(['email' => ['required', 'email'], 'role' => ['required', Rule::in([...PlatformAccess::ROLES, 'remove'])], 'reason' => ['required', 'string', 'min:20', 'max:1500']]);
        DB::transaction(function () use ($request, $data) {
            User::query()->where('is_platform_admin', true)->orderBy('id')->lockForUpdate()->get(['id']);
            app(PlatformAccess::class)->check($request->user()->fresh(), 'settings');
            $target = User::query()->where('email', strtolower(trim($data['email'])))->lockForUpdate()->firstOrFail();
            abort_if($target->id === $request->user()->id, 403, 'Use another superadmin to change your platform access.');
            $before = $target->only(['is_platform_admin', 'platform_role']);
            $target->forceFill(['is_platform_admin' => $data['role'] !== 'remove', 'platform_role' => $data['role'] === 'remove' ? 'support' : $data['role']])->save();
            app(PlatformAccess::class)->audit(null,$request->user()->id,'platform.role',$data['reason'],$before,['user_id' => $target->id, ...$target->only(['is_platform_admin', 'platform_role'])]);
        }, 3);

        return back()->with('status','Platform access changed and audited. Production privileged MFA still applies.');
    }
}
