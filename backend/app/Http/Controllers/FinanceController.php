<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\FinanceAccount;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinancePayment;
use App\Models\FinancePostingRequest;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceSupplierBill;
use App\Models\PurchaseOrder;
use App\Models\ExportShipment;
use App\Services\FinanceDocuments;
use App\Services\FinancePosting;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\ShipmentContribution;
use App\Support\BusinessPermissions;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceController extends Controller
{
    private function check(Request $request,string $permission):void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),$permission),403);
    }

    private function business(Request $request):Business
    {
        return $request->attributes->get('activeBusiness');
    }

    public function dashboard(Request $request):View
    {
        $this->check($request,'finance.view');
        $business=$this->business($request);
        $accounts=FinanceAccount::query()->where('business_id',$business->id)->orderBy('code')->get();
        $lines=FinanceJournalLine::query()->where('business_id',$business->id)
            ->get(['account_id','base_debit','base_credit']);
        $balances=[];
        foreach($lines as $line) {
            $balances[$line->account_id]=($balances[$line->account_id]??BigDecimal::zero())
                ->plus((string)$line->base_debit)->minus((string)$line->base_credit);
        }
        $totalDebit=BigDecimal::zero();$totalCredit=BigDecimal::zero();
        foreach($balances as $balance) {
            if($balance->isGreaterThan(0)) $totalDebit=$totalDebit->plus($balance);
            elseif($balance->isLessThan(0)) $totalCredit=$totalCredit->plus($balance->negated());
        }
        $unposted=[
            'invoices'=>CommercialInvoice::query()->where('business_id',$business->id)->where('posting_status','not_posted')->count(),
            'supplier_bills'=>FinanceSupplierBill::query()->where('business_id',$business->id)->where('posting_status','not_posted')->count(),
            'payments'=>FinancePayment::query()->where('business_id',$business->id)->where('status','pending')->count(),
            'shipment_costs'=>FinanceShipmentCost::query()->where('business_id',$business->id)->where('status','pending')->count(),
        ];
        $pending=FinancePostingRequest::query()->where('business_id',$business->id)
            ->whereIn('status',['pending','approved'])->count();
        $canSetup=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.setup');
        $existingKeys=$accounts->pluck('system_key')->all();
        $missingAccounts=array_values(array_filter(array_map(
            fn($definition)=>$definition[3],\App\Services\FinanceSetup::ACCOUNTS
        ),fn($key)=>!in_array($key,$existingKeys,true)));
        return view('finance.dashboard',compact(
            'business','accounts','balances','totalDebit','totalCredit',
            'unposted','pending','canSetup','missingAccounts'
        ));
    }

    public function setup(Request $request,FinanceSetup $setup):RedirectResponse
    {
        $this->check($request,'finance.setup');
        $created=$setup->activate($this->business($request));
        return back()->with('status',$created
            ? 'Financial chart activated with '.$created.' accounts.'
            : 'The financial chart is already configured.');
    }

    public function rates(Request $request):View
    {
        $this->check($request,'finance.rates.view');
        $business=$this->business($request);
        $rates=FinanceExchangeRate::query()->where('business_id',$business->id)
            ->orderByDesc('effective_on')->paginate(40);
        $canPropose=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.rates.propose');
        $canApprove=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.rates.approve');
        return view('finance.rates',compact('business','rates','canPropose','canApprove'));
    }

    public function proposeRate(Request $request,FinanceRates $rates):RedirectResponse
    {
        $this->check($request,'finance.rates.propose');
        $data=$request->validate([
            'currency'=>['required','alpha','size:3'],
            'rate_to_base'=>['required','numeric','gt:0','decimal:0,10','max:99999999999999'],
            'effective_on'=>['required','date','before_or_equal:today'],
            'source_note'=>['required','string','min:8','max:250'],
        ]);
        $rates->propose($this->business($request),$data,$request->user()->id);
        return redirect()->route('finance.rates')->with('status',
            'Exchange rate proposed. A different authorised person must approve it.');
    }

    public function approveRate(Request $request,int $rate,FinanceRates $rates):RedirectResponse
    {
        $this->check($request,'finance.rates.approve');
        $business=$this->business($request);
        $rate=FinanceExchangeRate::query()->where('business_id',$business->id)->findOrFail($rate);
        $rates->approve($rate,$request->user()->id);
        return back()->with('status','Exchange rate approved and locked for financial postings.');
    }

    public function documents(Request $request):View
    {
        $this->check($request,'finance.documents.view');
        $business=$this->business($request);
        $bills=FinanceSupplierBill::query()->where('business_id',$business->id)
            ->with('purchase.supplier','purchase.product')->latest()->paginate(15,['*'],'bills');
        $purchases=PurchaseOrder::query()->where('business_id',$business->id)
            ->where('status','received')->with(['supplier','product'])->orderByDesc('id')->limit(75)->get();
        $billed=FinanceSupplierBill::query()->where('business_id',$business->id)
            ->whereIn('purchase_order_id',$purchases->pluck('id'))->pluck('purchase_order_id')->all();
        $purchases=$purchases->filter(fn($p)=>!in_array($p->id,$billed,true));
        $payments=FinancePayment::query()->where('business_id',$business->id)
            ->with(['invoice.customer','bill.purchase','cashAccount'])->latest()->paginate(15,['*'],'payments');
        $costs=FinanceShipmentCost::query()->where('business_id',$business->id)
            ->with(['shipment','cashAccount'])->latest()->paginate(15,['*'],'costs');
        $invoices=CommercialInvoice::query()->where('business_id',$business->id)
            ->where('posting_status','posted')->with('customer')->latest()->limit(75)->get();
        $payableBills=FinanceSupplierBill::query()->where('business_id',$business->id)
            ->where('posting_status','posted')->with('purchase')->latest()->limit(75)->get();
        $shipments=ExportShipment::query()->where('business_id',$business->id)
            ->where('status','!=','cancelled')->latest()->limit(75)->get();
        $finance=app(FinanceDocuments::class);
        $invoiceBalances=$invoices->mapWithKeys(fn($i)=>[$i->id=>(string)$finance->outstanding($i)]);
        $billBalances=$payableBills->mapWithKeys(fn($i)=>[$i->id=>(string)$finance->outstanding($i)]);
        $bankProfiles=\App\Models\FinanceBankProfile::query()->where('business_id',$business->id)
            ->where('active',true)->orderBy('label')->get();
        $canCreate=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.documents.create');
        $paymentKey=(string)Str::uuid();
        $supplierPaymentKey=(string)Str::uuid();
        return view('finance.documents',compact(
            'business','bills','purchases','payments','costs','invoices',
            'payableBills','shipments','invoiceBalances','billBalances','canCreate','paymentKey','supplierPaymentKey','bankProfiles'
        ));
    }

    public function bill(Request $request,int $purchase,FinanceDocuments $finance):RedirectResponse
    {
        $this->check($request,'finance.documents.create');
        $data=$request->validate([
            'vendor_invoice_reference'=>['required','string','min:3','max:120'],
            'vendor_invoice_date'=>['required','date','before_or_equal:today'],
            'notes'=>['nullable','string','max:2000'],
        ]);
        $business=$this->business($request);
        $record=PurchaseOrder::query()->where('business_id',$business->id)->findOrFail($purchase);
        $finance->supplierBill($business,$record,$data,$request->user()->id);
        return redirect()->route('finance.documents')->with('status',
            'Supplier bill prepared from accepted produce. Submit it for review before posting inventory and payables.');
    }

    public function payment(Request $request,FinanceDocuments $finance):RedirectResponse
    {
        $this->check($request,'finance.documents.create');
        $data=$request->validate([
            'direction'=>['required',Rule::in(['in','out'])],
            'commercial_invoice_id'=>['required_if:direction,in','nullable','integer'],
            'supplier_bill_id'=>['required_if:direction,out','nullable','integer'],
            'cash_account_key'=>['required',Rule::in(['bank','cash'])],
            'bank_profile_id'=>['nullable','integer','min:1'],
            'amount'=>['required','numeric','gt:0','decimal:0,2','max:999999999999'],
            'payment_on'=>['required','date','before_or_equal:today'],
            'external_reference'=>['required','string','min:3','max:150'],
            'notes'=>['nullable','string','max:2000'],
            'idempotency_key'=>['required','uuid'],
        ]);
        $finance->payment($this->business($request),$data,$request->user()->id);
        return redirect()->route('finance.documents')->with('status',
            'Payment evidence recorded as pending. It has not yet affected the ledger or been bank-reconciled.');
    }

    public function cost(Request $request,FinanceDocuments $finance):RedirectResponse
    {
        $this->check($request,'finance.documents.create');
        $data=$request->validate([
            'shipment_id'=>['required','integer'],
            'category'=>['required',Rule::in([
                'freight','packing_material','ground_transport','handling','airline_fees','inspection','other'
            ])],
            'description'=>['required','string','min:5','max:250'],
            'currency'=>['required','alpha','size:3'],
            'amount'=>['required','numeric','gt:0','decimal:0,2','max:999999999999'],
            'cash_account_key'=>['required',Rule::in(['bank','cash'])],
            'bank_profile_id'=>['nullable','integer','min:1'],
            'paid_on'=>['required','date','before_or_equal:today'],
            'external_reference'=>['required','string','min:3','max:150'],
        ]);
        $finance->shipmentCost($this->business($request),$data,$request->user()->id);
        return redirect()->route('finance.documents')->with('status',
            'Shipment cost evidence recorded as pending. Finance approval and posting are still required.');
    }

    public function reviews(Request $request):View
    {
        $this->check($request,'finance.reviews.view');
        $business=$this->business($request);
        $requests=FinancePostingRequest::query()->where('business_id',$business->id)
            ->with('exchangeRate')->orderByDesc('id')->paginate(35);
        $canSubmit=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.reviews.request');
        $canApprove=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.reviews.approve');
        $canPost=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.reviews.post');
        $rates=FinanceExchangeRate::query()->where('business_id',$business->id)
            ->where('status','approved')->orderByDesc('effective_on')->get();
        $invoices=CommercialInvoice::query()->where('business_id',$business->id)
            ->where('posting_status','not_posted')->with('shipment')->latest()->limit(100)->get();
        $bills=FinanceSupplierBill::query()->where('business_id',$business->id)
            ->where('posting_status','not_posted')->latest()->limit(100)->get();
        $payments=FinancePayment::query()->where('business_id',$business->id)
            ->where('status','pending')->latest()->limit(100)->get();
        $costs=FinanceShipmentCost::query()->where('business_id',$business->id)
            ->where('status','pending')->latest()->limit(100)->get();
        $reliefs=\App\Models\FinanceInventoryRelief::query()->where('business_id',$business->id)
            ->where('posting_status','not_posted')->latest()->limit(100)->get();
        $creditNotes=\App\Models\FinanceCreditNote::query()->where('business_id',$business->id)
            ->where('posting_status','not_posted')->latest()->limit(100)->get();
        $openingBalances=\App\Models\FinanceOpeningBalance::query()->where('business_id',$business->id)
            ->where('posting_status','not_posted')->latest()->limit(100)->get();
        $balanceAdjustments=\App\Models\FinanceBalanceAdjustment::query()->where('business_id',$business->id)
            ->where('posting_status','not_posted')->latest()->limit(100)->get();
        $payrollRuns=\App\Models\FinancePayrollRun::query()->where('business_id',$business->id)
            ->where('posting_status','not_posted')->latest()->limit(100)->get();
        $payrollSettlements=\App\Models\FinancePayrollSettlement::query()->where('business_id',$business->id)
            ->where('status','pending')->latest()->limit(100)->get();
        $payrollReversals=\App\Models\FinancePayrollReversal::query()->where('business_id',$business->id)
            ->where('posting_status','not_posted')->with('run')->latest()->limit(100)->get();
        $transactionReversals=\App\Models\FinanceTransactionReversal::query()
            ->where('business_id',$business->id)->where('posting_status','not_posted')
            ->latest()->limit(100)->get();
        $partialReturns=\App\Models\FinancePartialBankReturn::query()
            ->where('business_id',$business->id)->where('posting_status','not_posted')
            ->latest()->limit(100)->get();
        $monetaryRevaluations=\App\Models\FinanceMonetaryRevaluation::query()
            ->where('business_id',$business->id)->where('posting_status','not_posted')
            ->latest()->limit(100)->get();
        $fxRevaluations=\App\Models\FinanceFxRevaluation::query()
            ->where('business_id',$business->id)->where('posting_status','not_posted')
            ->latest()->limit(100)->get();
        $foreignBankOpenings=\App\Models\FinanceForeignBankOpening::query()
            ->where('business_id',$business->id)->where('posting_status','not_posted')
            ->latest()->limit(100)->get();
        $hasPending=FinancePostingRequest::query()->where('business_id',$business->id)
            ->whereIn('status',['pending','approved'])
            ->get(['source_type','source_id'])->map(fn($r)=>$r->source_type.':'.$r->source_id)->all();
        return view('finance.reviews',compact(
            'business','requests','rates','invoices','bills','payments','costs',
            'canSubmit','canApprove','canPost','hasPending','reliefs','creditNotes',
            'openingBalances','balanceAdjustments','payrollRuns','payrollSettlements','payrollReversals',
            'transactionReversals','foreignBankOpenings','fxRevaluations',
            'partialReturns','monetaryRevaluations'
        ));
    }

    public function requestPosting(Request $request,string $type,int $source,FinancePosting $posting):RedirectResponse
    {
        $this->check($request,'finance.reviews.request');
        $data=$request->validate([
            'posting_on'=>['required','date','before_or_equal:today'],
            'exchange_rate_id'=>['nullable','integer'],
            'basis_notes'=>['required','string','min:20','max:3000'],
        ]);
        $data['exchange_rate_id']=isset($data['exchange_rate_id'])
            ?(int)$data['exchange_rate_id']:null;
        $posting->request($this->business($request),$type,$source,$data,$request->user()->id);
        return redirect()->route('finance.reviews')->with('status',
            'Posting submitted for independent finance approval.');
    }

    public function approvePosting(Request $request,int $review,FinancePosting $posting):RedirectResponse
    {
        $this->check($request,'finance.reviews.approve');
        $data=$request->validate(['notes'=>['required','string','min:10','max:2000']]);
        $record=FinancePostingRequest::query()->where('business_id',$this->business($request)->id)
            ->findOrFail($review);
        $posting->review($record,true,$request->user()->id,$data['notes']);
        return redirect()->route('finance.reviews')->with('status',
            'Financial posting approved. A separate authorised person must post it.');
    }

    public function rejectPosting(Request $request,int $review,FinancePosting $posting):RedirectResponse
    {
        $this->check($request,'finance.reviews.approve');
        $data=$request->validate(['notes'=>['required','string','min:10','max:2000']]);
        $record=FinancePostingRequest::query()->where('business_id',$this->business($request)->id)
            ->findOrFail($review);
        $posting->review($record,false,$request->user()->id,$data['notes']);
        return redirect()->route('finance.reviews')->with('status','Posting rejected with an audit note.');
    }

    public function post(Request $request,int $review,FinancePosting $posting):RedirectResponse
    {
        $this->check($request,'finance.reviews.post');
        $record=FinancePostingRequest::query()->where('business_id',$this->business($request)->id)
            ->findOrFail($review);
        $journal=$posting->post($record,$request->user()->id);
        return redirect()->route('finance.journal',$journal->id)->with('status',
            'Approved balanced journal posted. Original transaction and rate have been preserved.');
    }

    public function contribution(Request $request,int $shipment,ShipmentContribution $costing):View
    {
        $this->check($request,'finance.view');
        $shipment=ExportShipment::query()->where('business_id',$this->business($request)->id)
            ->findOrFail($shipment);
        $report=$costing->report($shipment);
        return view('finance.contribution',compact('shipment','report'));
    }

    public function journal(Request $request,int $journal):View
    {
        $this->check($request,'finance.view');
        $journal=FinanceJournal::query()->where('business_id',$this->business($request)->id)
            ->with('lines.account')->findOrFail($journal);
        return view('finance.journal',compact('journal'));
    }
}
