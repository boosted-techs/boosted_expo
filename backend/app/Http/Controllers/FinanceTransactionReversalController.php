<?php

namespace App\Http\Controllers;

use App\Models\FinancePayment;
use App\Models\FinancePayrollSettlement;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceTransactionReversal;
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
        $reversals=FinanceTransactionReversal::query()->where('business_id',$business->id)
            ->orderByDesc('id')->paginate(25);
        $canPrepare=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.payment.reversal.prepare');
        return view('finance.transaction-reversals',compact('business','payments','costs',
            'payroll','reversals','canPrepare'));
    }

    public function prepare(Request $request,string $type,int $id,
        FinanceTransactionCorrections $corrections):RedirectResponse
    {
        $this->check($request,'finance.payment.reversal.prepare');
        abort_unless(in_array($type,['payment','shipment_cost','payroll_settlement'],true),404);
        $data=$request->validate([
            'reason'=>['required','string','min:30','max:3000'],
            'external_evidence'=>['required','string','min:30','max:3000'],
        ]);
        $corrections->prepare($request->attributes->get('activeBusiness'),
            $type,$id,$data['reason'],$data['external_evidence'],$request->user()->id);
        return redirect()->route('finance.transaction-reversals.index')->with('status',
            'Correction prepared. It will not affect the original posted payment or balance until independently approved and posted. This action does not move money.');
    }
}
