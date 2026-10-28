<?php

namespace App\Http\Controllers;

use App\Models\CashCustody;
use App\Models\CommercialInvoice;
use App\Models\FinanceBankProfile;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceTradeCredit;
use App\Models\StockExceptionCase;
use App\Services\FinanceTradeCredits;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceTradeCreditController extends Controller
{
    private function check(Request $r, string $permission = 'finance.view'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $r, FinanceTradeCredits $service): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $r->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) $r->input('search'));
        $credits = FinanceTradeCredit::query()->where('business_id', $business->id)->with('workflow', 'case')->when($search !== '', fn ($q) => $q->whereHas('workflow', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhere('external_reference', 'like', '%'.$search.'%'))))->latest('id')->workspacePaginate(25, ['*'], 'credit_page')->withQueryString();
        $remaining = [];
        foreach ($credits as $credit) {
            if ($credit->workflow->posting_status === 'posted') {
                $remaining[$credit->id] = $service->remaining($credit);
            }
        }
        $cases = StockExceptionCase::query()->where('business_id', $business->id)->whereIn('kind', ['customer_return', 'supplier_return', 'quality_claim', 'recall'])->whereIn('status', ['open', 'investigating', 'awaiting_disposition'])->with('batch', 'shipment')->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'))->latest('id')->workspacePaginate(40, ['*'], 'case_page')->withQueryString();
        $invoices = CommercialInvoice::query()->where('business_id', $business->id)->where('posting_status', 'posted')->with('customer')->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'))->latest('id')->workspacePaginate(40, ['*'], 'invoice_page')->withQueryString();
        $bills = FinanceSupplierBill::query()->where('business_id', $business->id)->where('posting_status', 'posted')->with('purchase.supplier')->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'))->latest('id')->workspacePaginate(40, ['*'], 'bill_page')->withQueryString();
        $banks = FinanceBankProfile::query()->where('business_id', $business->id)->where('active', true)->orderBy('label')->get();
        $custodies = CashCustody::query()->where('business_id', $business->id)->where('active', true)->orderBy('name')->get();
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)->where('status', 'approved')->latest('effective_on')->get();
        $canCreate = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');

        return view('finance.trade-credits', compact('business', 'credits', 'remaining', 'cases', 'invoices', 'bills', 'banks', 'custodies', 'rates', 'canCreate'));
    }

    private function common(Request $r): array
    {
        $b = $r->attributes->get('activeBusiness');

        return $r->validate(['event_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($b->timezone)->toDateString()], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'request_key' => ['required', 'uuid']]);
    }

    public function store(Request $r, FinanceTradeCredits $service): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        if (is_string($r->input('document_choice')) && preg_match('/^(customer|supplier):([1-9][0-9]*)$/D', $r->input('document_choice'), $parts)) {
            $r->merge(['document_type' => $parts[1], 'document_id' => (int) $parts[2]]);
        }$data = [...$this->common($r), ...$r->validate(['document_type' => ['required', 'in:customer,supplier'], 'document_id' => ['required', 'integer', 'min:1'], 'case_id' => ['required', 'integer', 'min:1'], 'tax_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'due_on' => ['nullable', 'date_format:Y-m-d']])];
        $flow = $service->prepare($r->attributes->get('activeBusiness'), $data, $r->user()->id);

        return redirect()->route('finance.treasury.show', $flow->id)->with('status', 'Agreed credit prepared. Review the tax, original debt and any refund balance before posting.');
    }

    public function loss(Request $r, int $case, FinanceTradeCredits $service): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $b = $r->attributes->get('activeBusiness');
        $data = $r->validate(['event_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($b->timezone)->toDateString()], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'request_key' => ['required', 'uuid']]);
        $flow = $service->writeOff($b, StockExceptionCase::query()->where('business_id', $b->id)->findOrFail($case), $data, $r->user()->id);

        return redirect()->route('finance.treasury.show', $flow->id)->with('status', 'Supplier claim loss prepared for independent financial review.');
    }

    public function use(Request $r, int $credit, string $kind, FinanceTradeCredits $service): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $choice = $r->input('account_choice');
        if (is_string($choice) && preg_match('/^(bank|cash):([1-9][0-9]*)$/D', $choice, $parts)) {
            $r->merge([$parts[1] === 'bank' ? 'bank_profile_id' : 'cash_custody_id' => (int) $parts[2]]);
        } elseif ($choice === 'legacy:cash') {
            $r->merge(['account_key' => 'cash']);
        }
        $data = [...$this->common($r), ...$r->validate(['document_id' => [$kind === 'trade_credit_apply' ? 'required' : 'nullable', 'integer', 'min:1'], 'exchange_rate_id' => ['nullable', 'integer', 'min:1'], 'bank_profile_id' => ['nullable', 'integer', 'min:1'], 'cash_custody_id' => ['nullable', 'integer', 'min:1'], 'account_key' => ['nullable', 'in:cash,bank'], 'account_choice' => ['nullable', 'regex:/^(?:(?:bank|cash):[1-9][0-9]*|legacy:cash)$/D']])];
        $b = $r->attributes->get('activeBusiness');
        $flow = $service->use($b, FinanceTradeCredit::query()->where('business_id', $b->id)->findOrFail($credit), $kind, $data, $r->user()->id);

        return redirect()->route('finance.treasury.show', $flow->id)->with('status', 'Credit refund/application prepared for separate review.');
    }
}
