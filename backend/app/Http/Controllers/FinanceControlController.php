<?php

namespace App\Http\Controllers;

use App\Models\BusinessMembership;
use App\Models\CommercialInvoice;
use App\Models\FinanceAccount;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceBankProfile;
use App\Models\FinanceOpeningBalance;
use App\Models\FinancePayrollRun;
use App\Models\FinancePeriod;
use App\Models\FinancePeriodReopenRequest;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceForeignBankOpening;
use App\Services\FinanceForeignBankOpenings;
use App\Services\FinanceBalanceAdjustments;
use App\Services\FinanceBankProfiles;
use App\Services\FinanceBankAuthenticity;
use App\Services\FinanceOpeningBalances;
use App\Services\FinancePeriodControls;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceControlController extends Controller
{
    private function check(Request $request,string $permission):void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),$permission),403);
    }
    private function business(Request $request):\App\Models\Business
    {
        return $request->attributes->get('activeBusiness');
    }
    public function index(Request $request):View
    {
        $this->check($request,'finance.controls.view');
        $business=$this->business($request);
        $accounts=FinanceAccount::query()->where('business_id',$business->id)
            ->where('active',true)->whereIn('type',['asset','liability','equity'])->orderBy('code')->get();
        $opening=FinanceOpeningBalance::query()->where('business_id',$business->id)->first();
        $banks=FinanceBankProfile::query()->where('business_id',$business->id)->with('account')
            ->latest()->get();
        $foreignBanks=$banks->filter(fn($p)=>$p->active &&
            $p->currency!==$business->base_currency);
        $foreignOpenings=FinanceForeignBankOpening::query()
            ->where('business_id',$business->id)->orderByDesc('id')->get();
        $foreignRates=FinanceExchangeRate::query()->where('business_id',$business->id)
            ->where('status','approved')->orderByDesc('effective_on')->limit(100)->get();
        $reopenRequests=FinancePeriodReopenRequest::query()
            ->where('business_id',$business->id)->orderByDesc('id')->get();
        $periods=FinancePeriod::query()->where('business_id',$business->id)
            ->orderByDesc('period_from')->get();
        $adjustments=FinanceBalanceAdjustment::query()->where('business_id',$business->id)
            ->latest()->limit(100)->get();
        $invoices=CommercialInvoice::query()->where('business_id',$business->id)
            ->where('posting_status','posted')->where('tax_amount',0)
            ->whereDoesntHave('creditNote')->latest()->limit(75)->get();
        $bills=FinanceSupplierBill::query()->where('business_id',$business->id)
            ->where('posting_status','posted')->latest()->limit(75)->get();
        $access=app(BusinessPermissions::class);
        $membership=$request->attributes->get('membership');
        $canBank=$access->allows($membership,'finance.banks.manage');
        $canForeign=$access->allows($membership,'finance.foreign.opening.prepare');
        $canOpen=$access->allows($membership,'finance.opening.prepare');
        $canPeriod=$access->allows($membership,'finance.periods.manage');
        $canPeriodReview=$access->allows($membership,'finance.periods.review');
        $canReopen=$access->allows($membership,'finance.periods.reopen.request');
        $canReopenReview=$access->allows($membership,'finance.periods.reopen.review');
        $canAdjust=$access->allows($membership,'finance.adjustments.prepare');
        return view('finance.controls',compact('business','accounts','opening','banks','periods',
            'adjustments','invoices','bills','canBank','canOpen','canPeriod','canPeriodReview','canAdjust',
            'foreignBanks','foreignOpenings','foreignRates','canForeign',
            'reopenRequests','canReopen','canReopenReview'));
    }

    public function bank(Request $request,FinanceBankProfiles $service):RedirectResponse
    {
        $this->check($request,'finance.banks.manage');
        $data=$request->validate([
            'label'=>['required','string','min:3','max:100'],
            'institution'=>['required','string','min:3','max:100'],
            'currency'=>['required','alpha','size:3'],
            'masked_reference'=>['nullable','string','max:32',
                'regex:/^(?:[xX*#\s-]*[0-9]{2,4})$/D'],
        ]);
        $service->create($this->business($request),$data,$request->user()->id);
        return back()->with('status',
            'Named bank ledger added. New receipts and statements must identify the bank account.');
    }

    public function bankAccountIdentifier(Request $request,int $bank,
        FinanceBankAuthenticity $auth):RedirectResponse
    {
        $this->check($request,'finance.banks.manage');
        $data=$request->validate([
            'account_identifier'=>['required','string','min:6','max:100'],
        ]);
        $business=$this->business($request);
        $profile=FinanceBankProfile::query()->where('business_id',$business->id)
            ->findOrFail($bank);
        $auth->setAccountIdentifier($business,$profile,$data['account_identifier'],
            $request->user()->id);
        return redirect()->route('finance.controls')->with('status',
            'The bank account identity commitment has been saved as a one-way digest. The original reference is not stored. A trusted bank signature is still required for authentication.');
    }

    public function foreignOpening(Request $request,
        FinanceForeignBankOpenings $service):RedirectResponse
    {
        $this->check($request,'finance.foreign.opening.prepare');
        $data=$request->validate([
            'bank_profile_id'=>['required','integer','min:1'],
            'currency'=>['required','alpha','size:3'],
            'foreign_amount'=>['required','numeric','gt:0','decimal:0,2','max:999999999999999'],
            'exchange_rate_id'=>['required','integer','min:1'],
            'effective_on'=>['required','date_format:Y-m-d','before_or_equal:today'],
            'bank_evidence'=>['required','string','min:30','max:3000'],
        ]);
        $service->prepare($this->business($request),$data,$request->user()->id);
        return redirect()->route('finance.controls')->with('status',
            'Foreign bank opening prepared with its original-currency amount and approved base-rate translation. It awaits independent finance posting.');
    }

    public function opening(Request $request,FinanceOpeningBalances $service):RedirectResponse
    {
        $this->check($request,'finance.opening.prepare');
        $data=$request->validate([
            'effective_on'=>['required','date_format:Y-m-d','before_or_equal:today'],
            'lines'=>['required','array','min:2','max:30'],
            'lines.*.account_id'=>['nullable','integer','min:1'],
            'lines.*.debit'=>['nullable','numeric','min:0','decimal:0,2','max:999999999999999'],
            'lines.*.credit'=>['nullable','numeric','min:0','decimal:0,2','max:999999999999999'],
        ]);
        $data['lines']=array_values(array_filter($data['lines'],fn($row)=>
            filled($row['account_id']??null)
            || filled($row['debit']??null) || filled($row['credit']??null)));
        if(count($data['lines'])<2 || collect($data['lines'])->contains(fn($row)=>
            !filled($row['account_id']??null))) {
            return back()->withErrors(['lines'=>'Select an account for every amount and enter at least two account rows.']);
        }
        $service->prepare($this->business($request),$data,$request->user()->id);
        return redirect()->route('finance.controls')->with('status',
            'Opening balances prepared. A separate authorised reviewer must approve the journal.');
    }

    public function requestPeriodReopening(Request $request,int $period,
        FinancePeriodControls $service):RedirectResponse
    {
        $this->check($request,'finance.periods.reopen.request');
        $data=$request->validate([
            'reason'=>['required','string','min:30','max:3000'],
            'materiality_assessment'=>['required','string','min:30','max:3000'],
            'external_evidence'=>['required','string','min:30','max:3000'],
        ]);
        $period=FinancePeriod::query()->where('business_id',$this->business($request)->id)
            ->findOrFail($period);
        $service->requestReopen($period,$request->user()->id,$data);
        return redirect()->route('finance.controls')->with('status',
            'Formal period-reopening request recorded with the original closing snapshot. No historical posting has changed; an independent owner must approve reopening.');
    }

    public function reviewPeriodReopening(Request $request,int $case,string $decision,
        FinancePeriodControls $service):RedirectResponse
    {
        $this->check($request,'finance.periods.reopen.review');
        abort_unless(in_array($decision,['approve','reject'],true),404);
        $data=$request->validate([
            'notes'=>['required','string','min:20','max:3000'],
        ]);
        $case=FinancePeriodReopenRequest::query()
            ->where('business_id',$this->business($request)->id)->findOrFail($case);
        $service->reviewReopen($case,$decision==='approve',
            $request->user()->id,$data['notes']);
        return redirect()->route('finance.controls')->with('status',$decision==='approve'
            ?'The selected period has been formally reopened with a preserved audit trail. Complete the dated corrections, re-verify affected bank statements and request an independently approved fresh close.'
            :'Reopening rejected; the original locked period remains unchanged.');
    }

    public function period(Request $request,FinancePeriodControls $service):RedirectResponse
    {
        $this->check($request,'finance.periods.manage');
        $data=$request->validate([
            'period_from'=>['required','date_format:Y-m-d'],
            'period_to'=>['required','date_format:Y-m-d','before_or_equal:today'],
        ]);
        $service->create($this->business($request),$data['period_from'],$data['period_to'],
            $request->user()->id);
        return back()->with('status','Accounting period created. Finance can request its closure.');
    }
    private function periodOwned(Request $request,int $id):FinancePeriod
    {
        return FinancePeriod::query()->where('business_id',$this->business($request)->id)
            ->findOrFail($id);
    }
    public function submitPeriod(Request $request,int $period,FinancePeriodControls $service):RedirectResponse
    {
        $this->check($request,'finance.periods.manage');
        $data=$request->validate(['reason'=>['required','string','min:20','max:2000']]);
        $service->submit($this->periodOwned($request,$period),$request->user()->id,$data['reason']);
        return back()->with('status',
            'Period closing submitted. Backdated postings are held pending independent review.');
    }
    public function reviewPeriod(Request $request,int $period,string $action,
        FinancePeriodControls $service):RedirectResponse
    {
        $this->check($request,'finance.periods.review');
        abort_unless(in_array($action,['approve','reject'],true),404);
        $data=$request->validate(['reason'=>['required','string','min:10','max:2000']]);
        $service->review($this->periodOwned($request,$period),$action==='approve',
            $request->user()->id,$data['reason']);
        return back()->with('status',$action==='approve'
            ? 'The period is closed and historical postings are locked.'
            : 'Closing rejected with an audit note; the period is open again.');
    }

    public function adjustment(Request $request,string $kind,int $id,
        FinanceBalanceAdjustments $service):RedirectResponse
    {
        $this->check($request,'finance.adjustments.prepare');
        abort_unless(in_array($kind,['customer','supplier'],true),404);
        $data=$request->validate([
            'amount'=>['required','numeric','gt:0','decimal:0,2','max:999999999999999'],
            'reason'=>['required','string','min:20','max:3000'],
        ]);
        $service->prepare($this->business($request),$kind,$id,
            $data['amount'],$data['reason'],$request->user()->id);
        return back()->with('status',
            'Partial balance concession prepared. It is ineffective until independently approved and posted.');
    }
}
