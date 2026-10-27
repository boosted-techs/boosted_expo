<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\ExportShipment;
use App\Models\FinanceAccount;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceBankProfile;
use App\Models\FinanceCreditNote;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceForeignBankOpening;
use App\Models\FinanceFxRevaluation;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinanceMonetaryRevaluation;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceOperation;
use App\Models\FinancePartialBankReturn;
use App\Models\FinancePayment;
use App\Models\FinancePayrollReversal;
use App\Models\FinancePayrollRun;
use App\Models\FinancePayrollSettlement;
use App\Models\FinancePostingRequest;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceTransactionReversal;
use App\Models\PurchaseOrder;
use App\Models\TransportLeg;
use App\Services\FinanceDocuments;
use App\Services\FinancePosting;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\ShipmentContribution;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission), 403);
    }

    private function business(Request $request): Business
    {
        return $request->attributes->get('activeBusiness');
    }

    public function dashboard(Request $request): View
    {
        $this->check($request, 'finance.view');
        $business = $this->business($request);
        $accounts = FinanceAccount::query()->where('business_id', $business->id)->orderBy('code')->get();
        $lines = FinanceJournalLine::query()->where('business_id', $business->id)
            ->get(['account_id', 'base_debit', 'base_credit']);
        $balances = [];
        foreach ($lines as $line) {
            $balances[$line->account_id] = ($balances[$line->account_id] ?? BigDecimal::zero())
                ->plus((string) $line->base_debit)->minus((string) $line->base_credit);
        }
        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();
        foreach ($balances as $balance) {
            if ($balance->isGreaterThan(0)) {
                $totalDebit = $totalDebit->plus($balance);
            } elseif ($balance->isLessThan(0)) {
                $totalCredit = $totalCredit->plus($balance->negated());
            }
        }
        $unposted = [
            'invoices' => CommercialInvoice::query()->where('business_id', $business->id)->where('posting_status', 'not_posted')->count(),
            'supplier_bills' => FinanceSupplierBill::query()->where('business_id', $business->id)->where('posting_status', 'not_posted')->count(),
            'payments' => FinancePayment::query()->where('business_id', $business->id)->where('status', 'pending')->count(),
            'shipment_costs' => FinanceShipmentCost::query()->where('business_id', $business->id)->where('status', 'pending')->count(),
        ];
        $pending = FinancePostingRequest::query()->where('business_id', $business->id)
            ->whereIn('status', ['pending', 'approved'])->count();
        $canSetup = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.setup');
        $existingKeys = $accounts->pluck('system_key')->all();
        $missingAccounts = array_values(array_filter(array_map(
            fn ($definition) => $definition[3], FinanceSetup::ACCOUNTS
        ), fn ($key) => ! in_array($key, $existingKeys, true)));

        return view('finance.dashboard', compact(
            'business', 'accounts', 'balances', 'totalDebit', 'totalCredit',
            'unposted', 'pending', 'canSetup', 'missingAccounts'
        ));
    }

    public function setup(Request $request, FinanceSetup $setup): RedirectResponse
    {
        $this->check($request, 'finance.setup');
        $created = $setup->activate($this->business($request));

        return back()->with('status', $created
            ? 'Financial chart activated with '.$created.' accounts.'
            : 'The financial chart is already configured.');
    }

    public function rates(Request $request): View
    {
        $this->check($request, 'finance.rates.view');
        $business = $this->business($request);
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)
            ->orderByDesc('effective_on')->paginate(40);
        $canPropose = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.rates.propose');
        $canApprove = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.rates.approve');

        return view('finance.rates', compact('business', 'rates', 'canPropose', 'canApprove'));
    }

    public function proposeRate(Request $request, FinanceRates $rates): RedirectResponse
    {
        $this->check($request, 'finance.rates.propose');
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency')))]);
        $data = $request->validate([
            'currency' => ['required', 'string', app(BusinessCurrencies::class)->rule($request->attributes->get('activeBusiness'))],
            'rate_to_base' => ['required', 'numeric', 'gt:0', 'decimal:0,10', 'max:99999999999999'],
            'effective_on' => ['required', 'date', 'before_or_equal:'.now($request->attributes->get('activeBusiness')->timezone)->toDateString()],
            'source_note' => ['required', 'string', 'min:8', 'max:250'],
        ]);
        $rates->propose($this->business($request), $data, $request->user()->id);

        return redirect()->route('finance.rates')->with('status',
            'Exchange rate proposed. A different authorised person must approve it.');
    }

    public function approveRate(Request $request, int $rate, FinanceRates $rates): RedirectResponse
    {
        $this->check($request, 'finance.rates.approve');
        $business = $this->business($request);
        $rate = FinanceExchangeRate::query()->where('business_id', $business->id)->findOrFail($rate);
        $rates->approve($rate, $request->user()->id);

        return back()->with('status', 'Exchange rate approved and locked for financial postings.');
    }

    public function documents(Request $request): View
    {
        $this->check($request, 'finance.documents.view');
        $business = $this->business($request);
        $filters = $request->validate([
            'purchase_search' => ['nullable', 'string', 'max:120'],
            'invoice_search' => ['nullable', 'string', 'max:120'],
            'bill_search' => ['nullable', 'string', 'max:120'],
            'shipment_search' => ['nullable', 'string', 'max:120'],
        ]);
        $finance = app(FinanceDocuments::class);
        $bills = FinanceSupplierBill::query()->where('business_id', $business->id)
            ->with('purchase.supplier', 'purchase.product')->latest('id')->paginate(15, ['*'], 'bills')->withQueryString();
        $purchases = PurchaseOrder::query()->where('business_id', $business->id)
            ->whereIn('status', ['part_received', 'received'])->with(['supplier', 'product'])
            ->whereRaw('(SELECT COALESCE(SUM(accepted_quantity),0) FROM goods_receipts WHERE goods_receipts.business_id=purchase_orders.business_id AND goods_receipts.purchase_order_id=purchase_orders.id) > (SELECT COALESCE(SUM(accepted_quantity),0) FROM finance_supplier_bills WHERE finance_supplier_bills.business_id=purchase_orders.business_id AND finance_supplier_bills.purchase_order_id=purchase_orders.id)')
            ->when($filters['purchase_search'] ?? null, fn ($query, $term) => $query->where(fn ($query) => $query
                ->where('reference', 'like', '%'.$term.'%')->orWhere('vendor_name', 'like', '%'.$term.'%')
                ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', '%'.$term.'%'))))
            ->orderByDesc('id')->paginate(15, ['*'], 'purchase_page')->withQueryString();
        $payments = FinancePayment::query()->where('business_id', $business->id)
            ->with(['invoice.customer', 'bill.purchase', 'cashAccount'])->latest()->paginate(15, ['*'], 'payments');
        $costs = FinanceShipmentCost::query()->where('business_id', $business->id)
            ->with(['shipment', 'cashAccount'])->latest()->paginate(15, ['*'], 'costs');
        $invoices = $finance->outstandingQuery($business, true)->with('customer')
            ->when($filters['invoice_search'] ?? null, fn ($query, $term) => $query->where(fn ($query) => $query
                ->where('reference', 'like', '%'.$term.'%')
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$term.'%'))))
            ->orderByDesc('id')->paginate(15, ['*'], 'invoice_page')->withQueryString();
        $payableBills = $finance->outstandingQuery($business, false)->with('purchase.supplier')
            ->when($filters['bill_search'] ?? null, fn ($query, $term) => $query->where(fn ($query) => $query
                ->where('reference', 'like', '%'.$term.'%')->orWhere('vendor_invoice_reference', 'like', '%'.$term.'%')
                ->orWhereHas('purchase', fn ($purchase) => $purchase->where('reference', 'like', '%'.$term.'%')
                    ->orWhere('vendor_name', 'like', '%'.$term.'%')
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', '%'.$term.'%')))))
            ->orderByDesc('id')->paginate(15, ['*'], 'payable_page')->withQueryString();
        $shipments = ExportShipment::query()->where('business_id', $business->id)
            ->where('status', '!=', 'cancelled')
            ->when($filters['shipment_search'] ?? null, fn ($query, $term) => $query->where(fn ($query) => $query
                ->where('reference', 'like', '%'.$term.'%')->orWhere('air_waybill', 'like', '%'.$term.'%')))
            ->orderByDesc('id')->paginate(15, ['*'], 'shipment_page')->withQueryString();
        $invoiceBalances = $invoices->mapWithKeys(fn ($i) => [$i->id => (string) $finance->outstanding($i)]);
        $billBalances = $payableBills->mapWithKeys(fn ($i) => [$i->id => (string) $finance->outstanding($i)]);
        $bankProfiles = FinanceBankProfile::query()->where('business_id', $business->id)
            ->where('active', true)->orderBy('label')->get();
        $canCreate = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.documents.create');
        $paymentKey = (string) Str::uuid();
        $supplierPaymentKey = (string) Str::uuid();

        $transportLegs = TransportLeg::query()->where('business_id', $business->id)->whereIn('shipment_id', $shipments->modelKeys())->where('status', '!=', 'cancelled')->with('shipment')->orderBy('sequence')->get();

        return view('finance.documents', compact('transportLegs',
            'business', 'bills', 'purchases', 'payments', 'costs', 'invoices',
            'payableBills', 'shipments', 'invoiceBalances', 'billBalances', 'canCreate', 'paymentKey', 'supplierPaymentKey', 'bankProfiles'
        ));
    }

    public function bill(Request $request, int $purchase, FinanceDocuments $finance): RedirectResponse
    {
        $this->check($request, 'finance.documents.create');
        $data = $request->validate([
            'accepted_quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'vendor_invoice_reference' => ['required', 'string', 'min:3', 'max:120'],
            'vendor_invoice_date' => ['required', 'date', 'before_or_equal:'.now($request->attributes->get('activeBusiness')->timezone)->toDateString()],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vendor_invoice_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $business = $this->business($request);
        $record = PurchaseOrder::query()->where('business_id', $business->id)->findOrFail($purchase);
        $finance->supplierBill($business, $record, $data, $request->user()->id);

        return redirect()->route('finance.documents')->with('status',
            'Supplier bill prepared from accepted produce. Submit it for review before posting inventory and payables.');
    }

    public function payment(Request $request, FinanceDocuments $finance): RedirectResponse
    {
        $this->check($request, 'finance.documents.create');
        $data = $request->validate([
            'direction' => ['required', Rule::in(['in', 'out'])],
            'commercial_invoice_id' => ['required_if:direction,in', 'nullable', 'integer'],
            'supplier_bill_id' => ['required_if:direction,out', 'nullable', 'integer'],
            'cash_account_key' => ['required', Rule::in(['bank', 'cash'])],
            'bank_profile_id' => ['nullable', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'],
            'payment_on' => ['required', 'date', 'before_or_equal:'.now($request->attributes->get('activeBusiness')->timezone)->toDateString()],
            'external_reference' => ['required', 'string', 'min:3', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $finance->payment($this->business($request), $data, $request->user()->id);

        return redirect()->route('finance.documents')->with('status',
            'Payment evidence recorded as pending. It has not yet affected the ledger or been bank-reconciled.');
    }

    public function cost(Request $request, FinanceDocuments $finance): RedirectResponse
    {
        $this->check($request, 'finance.documents.create');
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency')))]);
        $data = $request->validate([
            'shipment_id' => ['required', 'integer'], 'transport_leg_id' => ['nullable', 'integer'],
            'category' => ['required', Rule::in([
                'freight', 'packing_material', 'ground_transport', 'handling', 'airline_fees', 'inspection', 'other',
            ])],
            'description' => ['required', 'string', 'min:5', 'max:250'],
            'currency' => ['required', 'string', app(BusinessCurrencies::class)->rule($request->attributes->get('activeBusiness'))],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'],
            'cash_account_key' => ['required', Rule::in(['bank', 'cash'])],
            'bank_profile_id' => ['nullable', 'integer', 'min:1'],
            'paid_on' => ['required', 'date', 'before_or_equal:'.now($request->attributes->get('activeBusiness')->timezone)->toDateString()],
            'external_reference' => ['required', 'string', 'min:3', 'max:150'],
        ]);
        $finance->shipmentCost($this->business($request), $data, $request->user()->id);

        return redirect()->route('finance.documents')->with('status',
            'Shipment cost evidence recorded as pending. Finance approval and posting are still required.');
    }

    public function reviews(Request $request): View
    {
        $this->check($request, 'finance.reviews.view');
        $business = $this->business($request);
        $request->validate(['source_search' => ['nullable', 'string', 'max:120']]);
        $sourceSearch = trim((string) $request->input('source_search'));
        $requests = FinancePostingRequest::query()->where('business_id', $business->id)
            ->with('exchangeRate')->orderByDesc('id')->paginate(35)->withQueryString();
        $canSubmit = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.reviews.request');
        $canApprove = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.reviews.approve');
        $canPost = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.reviews.post');
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)
            ->where('status', 'approved')->orderByDesc('effective_on')->get();
        $invoices = CommercialInvoice::query()->where('business_id', $business->id)
            ->where('posting_status', 'not_posted')->with('shipment')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_invoices_page')->withQueryString();
        $bills = FinanceSupplierBill::query()->where('business_id', $business->id)
            ->where('posting_status', 'not_posted')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_bills_page')->withQueryString();
        $payments = FinancePayment::query()->where('business_id', $business->id)
            ->where('status', 'pending')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_payments_page')->withQueryString();
        $costs = FinanceShipmentCost::query()->where('business_id', $business->id)
            ->where('status', 'pending')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_costs_page')->withQueryString();
        $reliefs = FinanceInventoryRelief::query()->where('business_id', $business->id)
            ->where('posting_status', 'not_posted')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_reliefs_page')->withQueryString();
        $creditNotes = FinanceCreditNote::query()->where('business_id', $business->id)
            ->where('posting_status', 'not_posted')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_creditNotes_page')->withQueryString();
        $openingBalances = FinanceOpeningBalance::query()->where('business_id', $business->id)
            ->where('posting_status', 'not_posted')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_openingBalances_page')->withQueryString();
        $balanceAdjustments = FinanceBalanceAdjustment::query()->where('business_id', $business->id)
            ->where('posting_status', 'not_posted')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_balanceAdjustments_page')->withQueryString();
        $payrollRuns = FinancePayrollRun::query()->where('business_id', $business->id)
            ->where('posting_status', 'not_posted')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_payrollRuns_page')->withQueryString();
        $payrollSettlements = FinancePayrollSettlement::query()->where('business_id', $business->id)
            ->where('status', 'pending')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_payrollSettlements_page')->withQueryString();
        $payrollReversals = FinancePayrollReversal::query()->where('business_id', $business->id)
            ->where('posting_status', 'not_posted')->with('run')->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_payrollReversals_page')->withQueryString();
        $transactionReversals = FinanceTransactionReversal::query()
            ->where('business_id', $business->id)->where('posting_status', 'not_posted')
            ->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_transactionReversals_page')->withQueryString();
        $partialReturns = FinancePartialBankReturn::query()
            ->where('business_id', $business->id)->where('posting_status', 'not_posted')
            ->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_partialReturns_page')->withQueryString();
        $monetaryRevaluations = FinanceMonetaryRevaluation::query()
            ->where('business_id', $business->id)->where('posting_status', 'not_posted')
            ->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_monetaryRevaluations_page')->withQueryString();
        $fxRevaluations = FinanceFxRevaluation::query()
            ->where('business_id', $business->id)->where('posting_status', 'not_posted')
            ->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_fxRevaluations_page')->withQueryString();
        $foreignBankOpenings = FinanceForeignBankOpening::query()
            ->where('business_id', $business->id)->where('posting_status', 'not_posted')
            ->when($sourceSearch !== '', fn ($query) => $query->where('reference', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('id')->paginate(15, ['*'], 'source_foreignBankOpenings_page')->withQueryString();
        $operations = FinanceOperation::query()->where('business_id', $business->id)->where('posting_status', 'not_posted')->when($sourceSearch !== '', fn ($q) => $q->where('reference', 'like', '%'.$sourceSearch.'%'))->latest('id')->paginate(15, ['*'], 'source_money_page')->withQueryString();
        $hasPending = FinancePostingRequest::query()->where('business_id', $business->id)
            ->whereIn('status', ['pending', 'approved'])
            ->get(['source_type', 'source_id'])->map(fn ($r) => $r->source_type.':'.$r->source_id)->all();

        return view('finance.reviews', compact(
            'business', 'requests', 'rates', 'invoices', 'bills', 'payments', 'costs',
            'canSubmit', 'canApprove', 'canPost', 'hasPending', 'reliefs', 'creditNotes',
            'openingBalances', 'balanceAdjustments', 'payrollRuns', 'payrollSettlements', 'payrollReversals',
            'transactionReversals', 'foreignBankOpenings', 'fxRevaluations',
            'partialReturns', 'monetaryRevaluations', 'operations'
        ));
    }

    public function requestPosting(Request $request, string $type, int $source, FinancePosting $posting): RedirectResponse
    {
        $this->check($request, 'finance.reviews.request');
        $data = $request->validate([
            'posting_on' => ['required', 'date', 'before_or_equal:'.now($request->attributes->get('activeBusiness')->timezone)->toDateString()],
            'exchange_rate_id' => ['nullable', 'integer'],
            'basis_notes' => ['required', 'string', 'min:20', 'max:3000'],
        ]);
        $data['exchange_rate_id'] = isset($data['exchange_rate_id'])
            ? (int) $data['exchange_rate_id'] : null;
        $posting->request($this->business($request), $type, $source, $data, $request->user()->id);

        return redirect()->route('finance.reviews')->with('status',
            'Posting submitted for independent finance approval.');
    }

    public function approvePosting(Request $request, int $review, FinancePosting $posting): RedirectResponse
    {
        $this->check($request, 'finance.reviews.approve');
        $data = $request->validate(['notes' => ['required', 'string', 'min:10', 'max:2000']]);
        $record = FinancePostingRequest::query()->where('business_id', $this->business($request)->id)
            ->findOrFail($review);
        $posting->review($record, true, $request->user()->id, $data['notes']);

        return redirect()->route('finance.reviews')->with('status',
            'Financial posting approved. A separate authorised person must post it.');
    }

    public function rejectPosting(Request $request, int $review, FinancePosting $posting): RedirectResponse
    {
        $this->check($request, 'finance.reviews.approve');
        $data = $request->validate(['notes' => ['required', 'string', 'min:10', 'max:2000']]);
        $record = FinancePostingRequest::query()->where('business_id', $this->business($request)->id)
            ->findOrFail($review);
        $posting->review($record, false, $request->user()->id, $data['notes']);

        return redirect()->route('finance.reviews')->with('status', 'Posting rejected with an audit note.');
    }

    public function post(Request $request, int $review, FinancePosting $posting): RedirectResponse
    {
        $this->check($request, 'finance.reviews.post');
        $record = FinancePostingRequest::query()->where('business_id', $this->business($request)->id)
            ->findOrFail($review);
        $journal = $posting->post($record, $request->user()->id);

        return redirect()->route('finance.journal', $journal->id)->with('status',
            'Approved balanced journal posted. Original transaction and rate have been preserved.');
    }

    public function contribution(Request $request, int $shipment, ShipmentContribution $costing): View
    {
        $this->check($request, 'finance.view');
        $shipment = ExportShipment::query()->where('business_id', $this->business($request)->id)
            ->findOrFail($shipment);
        $report = $costing->report($shipment);

        return view('finance.contribution', compact('shipment', 'report'));
    }

    public function journal(Request $request, int $journal): View
    {
        $this->check($request, 'finance.view');
        $journal = FinanceJournal::query()->where('business_id', $this->business($request)->id)
            ->with('lines.account')->findOrFail($journal);

        return view('finance.journal', compact('journal'));
    }
}
