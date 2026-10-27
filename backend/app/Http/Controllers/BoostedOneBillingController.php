<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\BillingPaymentMethod;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Business;
use App\Models\OnboardingRequest;
use App\Models\PlatformDelivery;
use App\Services\InviteBusinessUser;
use App\Services\OnboardingPackageMailer;
use App\Services\PlatformAccess;
use App\Services\PlatformBilling;
use App\Services\PlatformMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BoostedOneBillingController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['business_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:120'], 'invoice_state' => ['nullable', 'in:overdue,outstanding,paid']]);
        $id = $request->integer('business_id');

        return view('platform.billing', [
            'requests' => OnboardingRequest::latest('id')->paginate(15, ['*'], 'requests_page')->withQueryString(),
            'plans' => BillingPlan::orderBy('family_code')->orderByDesc('version')->get(),
            'businesses' => Business::orderBy('name')->get(), 'methods' => BillingPaymentMethod::latest()->get(),
            'subscriptions' => BillingSubscription::with(['business', 'plan'])->when($id, fn ($q) => $q->where('business_id', $id))->latest('id')->paginate(15, ['*'], 'subscriptions_page')->withQueryString(),
            'invoices' => BillingInvoice::query()->when($id, fn ($q) => $q->where('business_id', $id))
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('invoice_number', 'like', '%'.$request->input('search').'%')->orWhere('business_name', 'like', '%'.$request->input('search').'%')))
                ->when($request->input('invoice_state') === 'overdue', fn ($q) => $q->where('status', '!=', 'void')->whereDate('due_on', '<', today('Africa/Kampala'))->whereRaw('total_ugx-credit_ugx-paid_ugx+refunded_ugx>0'))
                ->when($request->input('invoice_state') === 'outstanding', fn ($q) => $q->where('status', '!=', 'void')->whereRaw('total_ugx-credit_ugx-paid_ugx+refunded_ugx>0'))
                ->when($request->input('invoice_state') === 'paid', fn ($q) => $q->where('status', 'paid'))
                ->latest('id')->paginate(20, ['*'], 'invoices_page')->withQueryString(),
        ]);
    }

    public function onboarding(Request $request)
    {
        return view('platform.onboarding', ['requests' => OnboardingRequest::query()->latest('id')->paginate(25)]);
    }

    public function sendPackages(OnboardingRequest $application, OnboardingPackageMailer $mailer)
    {
        try {
            $mailer->send($application);

            return back()->with('status', 'Package information emailed to '.$application->contact_email.'.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['email' => 'Package information could not be emailed. The application is saved; please check the mail service and retry.']);
        }
    }

    public function requestStatus(Request $request, OnboardingRequest $application, InviteBusinessUser $invites)
    {
        $data = $request->validate(['status' => 'required|in:contacted,declined,approved', 'admin_notes' => 'nullable|string|max:3000']);
        if ($application->business_id || $application->status === 'approved') {
            abort(409);
        }
        if ($data['status'] === 'approved') {
            $business = DB::transaction(function () use ($application, $request, $data) {
                $application = OnboardingRequest::query()->lockForUpdate()->findOrFail($application->id);
                abort_if($application->business_id || $application->status === 'approved', 409);
                $code = 'BO'.strtoupper(Str::random(10));
                $business = Business::create(['name' => $application->business_name, 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'batch', 'lifecycle_status' => 'onboarding', 'billing_mode' => 'trial', 'trial_ends_on' => today('Africa/Kampala')->addDays(14), 'owner_contact_email' => $application->contact_email]);
                $application->update(['business_id' => $business->id, 'status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'admin_notes' => $data['admin_notes'] ?? null]);

                return $business;
            });
            $invites->send($business, $application->contact_email, 'owner', $request->user()->id);
        } else {
            $application->update(['status' => $data['status'], 'admin_notes' => $data['admin_notes'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        }
        app(PlatformAccess::class)->audit($application->fresh()->business_id, $request->user()->id, 'onboarding.'.$data['status'], $data['admin_notes'] ?? 'Onboarding reviewed.', [], ['request_id' => $application->id]);

        return back()->with('status', 'Onboarding request updated.');
    }

    public function plan(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|alpha_dash|max:40|unique:billing_plans,code',
            'name' => 'required|string|max:120', 'description' => 'nullable|string|max:1500',
            'monthly_price_ugx' => 'nullable|integer|min:0|max:999999999999', 'quarterly_price_ugx' => 'nullable|integer|min:0|max:999999999999',
            'annual_price_ugx' => 'nullable|integer|min:0|max:999999999999',
            'modules_specified' => ['sometimes', 'boolean'], 'modules' => ['nullable', 'array'], 'modules.*' => ['string', 'distinct', Rule::in(PlatformAccess::MODULES)],
            'seat_limit' => ['nullable', 'integer', 'between:1,100000'], 'storage_limit_bytes' => ['nullable', 'integer', 'min:0', 'max:1099511627776'],
        ]);
        if (! empty($data['modules_specified'])) {
            $data['modules'] = $data['modules'] ?? [];
        } unset($data['modules_specified']);
        $plan = BillingPlan::create($data + ['family_code' => $data['code'], 'version' => 1, 'is_public' => true, 'is_active' => true]);
        app(PlatformAccess::class)->audit(null, $request->user()->id, 'plan.created', 'New commercial plan created.', [], $plan->toArray());

        return back()->with('status', 'Subscription plan created.');
    }

    public function method(Request $request)
    {
        $data = $request->validate([
            'label' => 'required|string|max:120', 'type' => 'required|in:bank,mobile_money,other',
            'provider' => 'required|string|max:120', 'account_name' => 'required|string|max:150',
            'account_number' => 'required|string|max:120', 'instructions' => 'nullable|string|max:1500',
        ]);
        $method = BillingPaymentMethod::create($data);
        app(PlatformAccess::class)->audit(null, $request->user()->id, 'billing.method', 'Official payment instructions created.', [], ['method_id' => $method->id, 'label' => $method->label]);

        return back()->with('status', 'Payment instructions saved.');
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'business_id' => 'required|exists:businesses,id', 'billing_plan_id' => 'required|exists:billing_plans,id',
            'billing_email' => 'required|email|max:255',
            'billing_cycle' => 'required|in:monthly,quarterly,annual,one_time',
            'price_ugx' => 'required|integer|min:0|max:999999999999',
            'tax_rate' => 'required|integer|min:0|max:100',
            'starts_on' => 'required|date',
            'agreement_notes' => 'nullable|string|max:3000',
        ]);
        $data['billing_email'] = strtolower($data['billing_email']);
        $data['status'] = 'pending';
        $data['next_invoice_on'] = $data['starts_on'];
        DB::transaction(function () use ($data, $request) {
            $business = Business::query()->lockForUpdate()->findOrFail($data['business_id']);
            if (BillingSubscription::query()->where('business_id', $business->id)->where('status', '!=', 'cancelled')->whereNull('cancelled_at')->exists()) {
                throw ValidationException::withMessages(['business_id' => 'This business already has an open agreement. Change it or cancel it explicitly before creating another.']);
            }
            $plan = BillingPlan::query()->where('is_active', true)->findOrFail($data['billing_plan_id']);
            $s = BillingSubscription::create([...$data, 'terms_snapshot' => app(PlatformBilling::class)->planTerms($plan)]);
            $business->update(['billing_mode' => $business->trial_ends_on?->gte(today('Africa/Kampala')) ? 'trial' : 'subscribed']);
            app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'subscription.created', $data['agreement_notes'] ?? 'Commercial terms recorded.', [], $s->toArray());
        }, 3);

        return back()->with('status', 'Commercial agreement created. Issue the first invoice after confirming terms.');
    }

    public function invoice(Request $request, BillingSubscription $subscription)
    {
        $data = $request->validate([
            'billing_payment_method_id' => 'required|exists:billing_payment_methods,id',
            'period_start' => 'required|date_format:Y-m-d',
            'due_on' => 'required|date', 'notes' => 'nullable|string|max:3000',
        ]);
        $method = BillingPaymentMethod::findOrFail($data['billing_payment_method_id']);
        abort_unless($method->is_active, 422);
        $invoice = app(PlatformBilling::class)->invoice($subscription, $method, $data, $request->user()->id);

        return redirect()->route('platform.billing.invoice.show', $invoice)->with('status', 'Invoice issued. Send it to the client after verifying the payment details.');
    }

    public function show(BillingInvoice $invoice)
    {
        $invoice->load(['business', 'subscription.plan', 'payments', 'adjustments']);
        $deliveries = app(PlatformAccess::class)->allows(request()->user(), 'billing') ? PlatformDelivery::query()->where('business_id', $invoice->business_id)->where('source_id', $invoice->id)->whereIn('kind', ['invoice', 'invoice_reminder'])->latest('id')->paginate(15) : collect();

        return view('billing.invoice', compact('invoice', 'deliveries'));
    }

    public function email(BillingInvoice $invoice)
    {
        abort_if($invoice->voided_at, 409);
        $mailer = app(PlatformMail::class);
        $d = $mailer->prepare($invoice->business_id, 'invoice', $invoice->id, $invoice->billing_email, [], 'invoice:'.$invoice->id.':'.today('Africa/Kampala')->toDateString());
        $mailer->deliver($d);
        app(PlatformAccess::class)->audit($invoice->business_id, request()->user()->id, 'invoice.email', 'Invoice email requested.', [], ['invoice_id' => $invoice->id, 'delivery_id' => $d->id]);

        return back()->with('status', 'Invoice delivery attempt recorded; review transport status before assuming inbox delivery.');
    }

    public function pay(Request $request, BillingInvoice $invoice)
    {
        $data = $request->validate([
            'billing_payment_method_id' => 'required|exists:billing_payment_methods,id',
            'amount_ugx' => 'required|integer|min:1|max:999999999999',
            'reference' => 'required|string|min:3|max:150',
            'received_on' => 'required|date|before_or_equal:'.today('Africa/Kampala')->toDateString(),
            'notes' => 'nullable|string|max:2000',
        ]);
        app(PlatformBilling::class)->pay($invoice, $data, $request->user()->id);

        return back()->with('status', 'Verified payment recorded.');
    }

    public function customer()
    {
        $business = request()->attributes->get('activeBusiness');
        $membership = request()->attributes->get('membership');
        abort_unless(in_array($membership->role, ['owner', 'admin', 'accountant'], true), 403);

        return view('billing.customer', [
            'subscriptions' => BillingSubscription::with('plan')->where('business_id', $business->id)->latest()->get(),
            'invoices' => BillingInvoice::where('business_id', $business->id)->latest()->get(),
        ]);
    }

    public function customerInvoice(BillingInvoice $invoice)
    {
        $business = request()->attributes->get('activeBusiness');
        $membership = request()->attributes->get('membership');
        abort_unless(in_array($membership->role, ['owner', 'admin', 'accountant'], true), 403);
        abort_unless($invoice->business_id === $business->id, 404);

        return $this->show($invoice);
    }
}
