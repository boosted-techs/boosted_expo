<?php

namespace App\Http\Controllers;

use App\Models\FinanceBankProfile;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceRegisterEntry;
use App\Models\LoanFacility;
use App\Models\LoanInstalment;
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
        $loans = LoanFacility::query()->where('business_id', $business->id)->when($r->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$r->input('search').'%')->orWhere('lender_name', 'like', '%'.$r->input('search').'%')))->orderBy('matures_on')->paginate(25)->withQueryString();
        $states = $loans->mapWithKeys(fn ($l) => [$l->id => app(LoanRegister::class)->state($l)]);
        $canManage = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');

        return view('finance.loans.index', compact('business', 'loans', 'states', 'canManage'));
    }

    public function store(Request $r, LoanRegister $register): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $business = $r->attributes->get('activeBusiness');
        $data = $r->validate(['reference' => ['required', 'string', 'min:3', 'max:65'], 'lender_name' => ['required', 'string', 'min:3', 'max:150'], 'lender_kind' => ['required', 'in:external,owner'], 'currency' => ['required', app(BusinessCurrencies::class)->rule($business)], 'limit_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'revolving' => ['nullable', 'boolean'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'matures_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'agreement_reference' => ['required', 'string', 'min:5', 'max:200'], 'terms' => ['required', 'string', 'min:20', 'max:5000']]);
        $loan = $register->create($business, $data, $r->user()->id);

        return redirect()->route('finance.loans.show', $loan->id)->with('status', 'Borrowing agreement registered. Only posted drawdowns/openings create a loan balance.');
    }

    public function show(Request $r, int $loan): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $loan = LoanFacility::query()->where('business_id', $business->id)->findOrFail($loan);
        $state = app(LoanRegister::class)->state($loan);
        $instalments = LoanInstalment::query()->where('business_id', $business->id)->where('loan_id', $loan->id)->orderBy('due_on')->paginate(30, ['*'], 'schedule_page')->withQueryString();
        $remaining = $instalments->mapWithKeys(fn ($i) => [$i->id => app(LoanRegister::class)->instalmentBalances($i)]);
        $entries = FinanceRegisterEntry::query()->where('business_id', $business->id)->where('loan_id', $loan->id)->latest('id')->paginate(25, ['*'], 'entry_page')->withQueryString();
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)->where('status', 'approved')->where('currency', $loan->currency)->orderByDesc('effective_on')->get();
        $banks = FinanceBankProfile::query()->where('business_id', $business->id)->where('active', true)->where('currency', $loan->currency)->orderBy('label')->get();
        $canManage = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');

        return view('finance.loans.show', compact('business', 'loan', 'state', 'instalments', 'remaining', 'entries', 'rates', 'banks', 'canManage'));
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
