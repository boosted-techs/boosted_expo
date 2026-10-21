<?php

namespace App\Http\Controllers;

use App\Models\FinancePayment;
use App\Models\FinancePayrollSettlement;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceTransactionReversal;
use App\Models\FinancePartialBankReturn;
use App\Services\FinancePartialBankReturns;
use Brick\Math\BigDecimal;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankStatementLine;
use App\Services\FinanceTransactionCorrections;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceTransactionReversalController extends Controller
{
    private function check(Request $request,string $permission):void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),$permission),403);
    }

    public function index(Request $request):View
    {
        $this->check($request,'finance.documents.view');
        $business=$request->attributes->get('activeBusiness');
        $payments=FinancePayment::query()->where('business_id',$business->id)
            ->where('status','posted')->latest()->limit(75)->get();
        $costs=FinanceShipmentCost::query()->where('business_id',$business->id)
            ->where('status','posted')->latest()->limit(75)->get();
        $payroll=FinancePayrollSettlement::query()->where('business_id',$business->id)
            ->where('status','posted')->latest()->limit(75)->get();
        $partialReturns=FinancePartialBankReturn::query()->where('business_id',$business->id)
            ->orderByDesc('id')->limit(75)->get();
        $reversals=FinanceTransactionReversal::query()->where('business_id',$business->id)
            ->orderByDesc('id')->paginate(25);
        $sourceMatches=FinanceBankMatch::query()->where('business_id',$business->id)
            ->whereIn('status',['pending','confirmed'])->with('line.statement')
            ->get()->keyBy(fn($m)=>($m->source_type??'').':'.($m->source_id??0));
        $reservedPartialIds=FinancePartialBankReturn::query()
            ->where('business_id',$business->id)->pluck('return_bank_line_id')->all();
        $reservedReturnIds=FinanceTransactionReversal::query()
            ->where('business_id',$business->id)->whereNotNull('return_bank_line_id')
            ->pluck('return_bank_line_id')->all();
        $returnLines=FinanceBankStatementLine::query()->where('business_id',$business->id)
            ->whereHas('statement',fn($q)=>$q->where('status','open'))
            ->whereDoesntHave('match',fn($q)=>$q->whereIn('status',['pending','confirmed']))
            ->with('statement')->orderByDesc('id')->limit(250)->get();
        $canPrepare=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.payment.reversal.prepare');
        return view('finance.transaction-reversals',compact('business','payments','costs',
            'payroll','reversals','partialReturns','canPrepare','sourceMatches',
            'returnLines','reservedReturnIds','reservedPartialIds'));
    }

    public function prepare(Request $request,string $type,int $id,
        FinanceTransactionCorrections $corrections):RedirectResponse
    {
        $this->check($request,'finance.payment.reversal.prepare');
        abort_unless(in_array($type,['payment','shipment_cost','payroll_settlement'],true),404);
        $data=$request->validate([
            'reason'=>['required','string','min:30','max:3000'],
            'external_evidence'=>['required','string','min:30','max:3000'],
            'return_bank_line_id'=>['nullable','integer','min:1'],
        ]);
        $business=$request->attributes->get('activeBusiness');
        $returnLineId=isset($data['return_bank_line_id'])?(int)$data['return_bank_line_id']:null;
        if($returnLineId!==null) {
            $returnLine=\App\Models\FinanceBankStatementLine::query()
                ->where('business_id',$business->id)->findOrFail($returnLineId);
            $source=match($type){
                'payment'=>FinancePayment::query(),
                'shipment_cost'=>FinanceShipmentCost::query(),
                default=>null,
            };
            if($source) {
                $original=$source->where('business_id',$business->id)->findOrFail($id);
                if(BigDecimal::of((string)$returnLine->amount)
                    ->isLessThan((string)$original->amount)) {
                    app(FinancePartialBankReturns::class)->prepare(
                        $business,$type,$id,$returnLineId,$data['reason'],
                        $data['external_evidence'],$request->user()->id);
                    return redirect()->route('finance.transaction-reversals.index')->with('status',
                        'Partial bank return reserved for independent review. No money was moved or original journal deleted.');
                }
            }
        }
        $corrections->prepare($business,$type,$id,$data['reason'],
            $data['external_evidence'],$request->user()->id,$returnLineId);
        return redirect()->route('finance.transaction-reversals.index')->with('status',
            'Correction prepared. It will not affect the original posted payment or balance until independently approved and posted. This action does not move money.');
    }
}
