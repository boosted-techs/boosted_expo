<?php
namespace App\Http\Controllers;

use App\Models\FinanceAccount;
use App\Services\FinanceStatements;
use App\Support\BusinessPermissions;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceReportController extends Controller
{
    private function check(Request $request):void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.reports.view'),403);
    }
    private function dates(Request $request):array
    {
        $data=$request->validate([
            'from'=>['nullable','date_format:Y-m-d'],
            'to'=>['nullable','date_format:Y-m-d','before_or_equal:today'],
        ]);
        $from=$data['from']??now()->startOfYear()->format('Y-m-d');
        $to=$data['to']??now()->format('Y-m-d');
        abort_unless($from<=$to,422,'Report start date must be before end date.');
        return [$from,$to];
    }
    public function index(Request $request,FinanceStatements $finance):View
    {
        $this->check($request);
        [$from,$to]=$this->dates($request);
        $business=$request->attributes->get('activeBusiness');
        $result=$finance->report($business,$from,$to);
        $accounts=FinanceAccount::query()->where('business_id',$business->id)
            ->orderBy('code')->get();
        return view('finance.reports',compact('business','result','accounts','from','to'));
    }
    public function ledger(Request $request,int $account,FinanceStatements $finance):View
    {
        $this->check($request);
        [$from,$to]=$this->dates($request);
        $business=$request->attributes->get('activeBusiness');
        $account=FinanceAccount::query()->where('business_id',$business->id)->findOrFail($account);
        $result=$finance->ledger($business,$account,$from,$to);
        return view('finance.account-ledger',compact('business','account','result','from','to'));
    }
}