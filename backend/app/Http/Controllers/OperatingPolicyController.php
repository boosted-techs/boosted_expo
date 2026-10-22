<?php

namespace App\Http\Controllers;

use App\Models\BusinessOperatingPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperatingPolicyController extends Controller
{
    public function update(Request $request):RedirectResponse
    {
        abort_unless($request->attributes->get('membership')->role==='owner',403,
            'Only the company owner may change business approval policies.');
        $data=$request->validate([
            'dispatch_approval_required'=>['required','boolean'],
            'buyer_link_days'=>['required','integer','between:1,30'],
        ]);
        $business=$request->attributes->get('activeBusiness');
        DB::transaction(function()use($business,$request,$data){
            \App\Models\Business::query()->whereKey($business->id)
                ->lockForUpdate()->firstOrFail();
            BusinessOperatingPolicy::query()->updateOrCreate(
                ['business_id'=>$business->id],
                [...$data,'updated_by'=>$request->user()->id],
            );
        },3);
        return redirect()->route('business.settings')->with('status',
            'Approval and buyer-access policies updated. Independent finance posting rules remain mandatory.');
    }
}
