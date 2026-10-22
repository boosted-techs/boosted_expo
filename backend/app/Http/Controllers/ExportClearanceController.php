<?php

namespace App\Http\Controllers;

use App\Models\ExportClearanceCheck;
use App\Models\ExportShipment;
use App\Services\ExportClearanceRegister;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExportClearanceController extends Controller
{
    private function own(Request $request,int $shipment):ExportShipment
    {
        return ExportShipment::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($shipment);
    }

    public function submit(Request $request,int $shipment,
        ExportClearanceRegister $register):RedirectResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'documents.generate'),403);
        $data=$request->validate([
            'document_kind'=>['required',Rule::in(array_keys(ExportClearanceRegister::TYPES))],
            'authority_name'=>['required','string','min:3','max:180'],
            'external_reference'=>['required','string','min:3','max:180'],
            'valid_until'=>['nullable','date_format:Y-m-d'],
            'source_evidence'=>['required','string','min:30','max:3000'],
        ]);
        $register->submit($this->own($request,$shipment),$data,$request->user()->id);
        return back()->with('status',
            'Export document reference registered for independent internal review. No authority or customs service has been contacted.');
    }

    public function review(Request $request,int $shipment,int $record,
        string $action,ExportClearanceRegister $register):RedirectResponse
    {
        abort_unless(in_array($action,['accept','reject'],true),404);
        abort_unless(in_array($request->attributes->get('membership')->role,
            ['owner','admin'],true),403);
        $this->own($request,$shipment);
        $data=$request->validate(['note'=>['required','string','min:20','max:2000']]);
        $record=ExportClearanceCheck::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)
            ->where('shipment_id',$shipment)->findOrFail($record);
        $register->review($record,$request->user()->id,$action==='accept',$data['note']);
        return back()->with('status',
            'Internal document review saved. This is not official customs, carrier or issuing-authority validation.');
    }
}
