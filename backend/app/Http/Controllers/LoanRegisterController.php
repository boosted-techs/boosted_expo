<?php

namespace App\Http\Controllers;

use App\Models\CashCustody;
use App\Models\FinanceBankProfile;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceRegisterEntry;
use App\Models\LoanAgreementAmendment;
use App\Models\LoanFacility;
use App\Models\LoanInstalment;
use App\Services\LoanAgreementAmendments;
use App\Services\LoanRegister;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoanRegisterController extends Controller
{
    private function check(Request $r, string $permission = 'finance.view'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $r): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $r->validate(['search' => ['nullable', 'string', 'max:120']]);
        $loans = LoanFacility::query()->where('business_id', $business->id)->when($r->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$r->input('search').'%')->orWhere('lender_name', 'like', '%'.$r->input('search').'%')))->orderBy('matures_on')->workspacePaginate(25)->withQueryString();
        $states = $loans->mapWithKeys(fn ($l) => [$l->id => app(LoanRegister::class)->state($l)]);
        $canManage = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');

        return view('finance.loans.index', compact('business', 'loans', 'states', 'canManage'));
    }

    public function store(Request $r, LoanRegister $register): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $business = $r->attributes->get('activeBusiness');
        $data = $r->validate(['loan_direction' => ['nullable', 'in:borrow,lend'], 'reference' => ['required', 'string', 'min:3', 'max:65'], 'lender_name' => ['required', 'string', 'min:3', 'max:150'], 'lender_kind' => ['required', 'in:external,owner'], 'currency' => ['required', app(BusinessCurrencies::class)->rule($business)], 'limit_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'revolving' => ['nullable', 'boolean'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'matures_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'agreement_reference' => ['required', 'string', 'min:5', 'max:200'], 'terms' => ['required', 'string', 'min:20', 'max:5000']]);
        $loan = $register->create($business, $data, $r->user()->id);

        return redirect()->route('finance.loans.show', $loan->id)->with('status', 'Loan agreement registered. Record its actual money movement or verified opening to create a balance.');
    }

    public function show(Request $r, int $loan): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $loan = LoanFacility::query()->where('business_id', $business->id)->findOrFail($loan);
        $state = app(LoanRegister::class)->state($loan);
        $instalments = LoanInstalment::query()->where('business_id', $business->id)->where('loan_id', $loan->id)->orderBy('due_on')->workspacePaginate(30, ['*'], 'schedule_page')->withQueryString();
        $remaining = $instalments->mapWithKeys(fn ($i) => [$i->id => app(LoanRegister::class)->instalmentBalances($i)]);
        $entries = FinanceRegisterEntry::query()->where('business_id', $business->id)->where('loan_id', $loan->id)->latest('id')->workspacePaginate(25, ['*'], 'entry_page')->withQueryString();
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)->where('status', 'approved')->where('currency', $loan->currency)->orderByDesc('effective_on')->get();
        $custodies = CashCustody::query()->where('business_id', $business->id)->where('active', true)->orderBy('name')->get();
        $banks = FinanceBankProfile::query()->where('business_id', $business->id)->where('active', true)->where('currency', $loan->currency)->orderBy('label')->get();
        $canManage = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');

        $amendments = LoanAgreementAmendment::query()->where('business_id', $business->id)->where('loan_id', $loan->id)->latest('id')->workspacePaginate(20, ['*'], 'amendment_page')->withQueryString();
        $canReview = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.reviews.approve');

        return view('finance.loans.show', compact('business', 'loan', 'state', 'instalments', 'remaining', 'entries', 'rates', 'banks', 'custodies', 'canManage', 'amendments', 'canReview'));
    }

    public function amend(Request $r, int $loan, LoanAgreementAmendments $service): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $b = $r->attributes->get('activeBusiness');
        $data = $r->validate(['effective_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now($b->timezone)->toDateString()], 'limit_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'revolving' => ['nullable', 'boolean'], 'resume_lending' => ['nullable', 'boolean'], 'matures_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:effective_on'], 'agreement_reference' => ['required', 'string', 'min:5', 'max:200'], 'terms' => ['required', 'string', 'min:20', 'max:5000'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $service->propose($b, LoanFacility::query()->where('business_id', $b->id)->findOrFail($loan), $data, $r->user()->id);

        return back()->with('status', 'Changed lender terms proposed. A separate reviewer applies them on their effective date.');
    }

    public function reviewAmendment(Request $r, int $amendment, string $decision, LoanAgreementAmendments $service): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $data = $r->validate(['note' => ['required', 'string', 'min:20', 'max:3000']]);
        $b = $r->attributes->get('activeBusiness');
        $service->review($b, LoanAgreementAmendment::query()->where('business_id', $b->id)->findOrFail($amendment), $decision === 'approve', $data['note'], $r->user()->id);

        return back()->with('status', 'Loan agreement amendment reviewed; posted debt remains supported by its original journals.');
    }

    public function cancelSchedule(Request $r, int $instalment, LoanRegister $register): RedirectResponse
    {
        $this->check($r, 'finance.loans.schedule.cancel');
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $record = LoanInstalment::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($instalment);
        $register->cancelInstalment($record, $data['reason'], $r->user()->id);

        return back()->with('status', 'Schedule remainder cancelled with history retained. Posted principal, interest and actual repayments are unchanged. Enter the replacement agreed dates when applicable.');
    }

    public function schedule(Request $r, int $loan, LoanRegister $register): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $business = $r->attributes->get('activeBusiness');
        $loan = LoanFacility::query()->where('business_id', $business->id)->findOrFail($loan);
        $data = $r->validate(['due_on' => ['required', 'date_format:Y-m-d'], 'principal' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'interest' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'fees' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'reference' => ['required', 'string', 'min:3', 'max:150']]);
        $register->schedule($business, $loan, $data, $r->user()->id);

        return back()->with('status', 'Agreed instalment recorded as a schedule. It does not itself accrue interest or move cash.');
    }
}
