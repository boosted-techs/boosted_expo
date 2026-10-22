<?php

namespace App\Http\Controllers;

use App\Services\StockQualityControls;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StockQualityController extends Controller
{
    public function change(Request $request,int $batch,string $action,
        StockQualityControls $service):RedirectResponse
    {
        abort_unless(in_array($action,['hold','release'],true),404);
        $permission=$action==='hold'?'stock.quality.hold':'stock.quality.release';
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),$permission),403);
        $data=$request->validate([
            'reason'=>['required','string','min:10','max:500'],
        ]);
        $service->change($request->attributes->get('activeBusiness'),
            $batch,$action==='hold',$data['reason'],$request->user()->id);
        return back()->with('status',$action==='hold'
            ?'Quality hold recorded. The affected batch is blocked from further packing or dispatch.'
            :'Quality hold released with an audit note.');
    }
}
