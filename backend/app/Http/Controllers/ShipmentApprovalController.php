<?php

namespace App\Http\Controllers;

use App\Models\ExportShipment;
use App\Services\ShipmentDispatchApproval;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShipmentApprovalController extends Controller
{
    private function own(Request $request,int $id):ExportShipment
    {
        return ExportShipment::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($id);
    }
    public function submit(Request $request,int $shipment,
        ShipmentDispatchApproval $service):RedirectResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'shipments.manage'),403);
        $service->request($this->own($request,$shipment),$request->user()->id);
        return back()->with('status',
            'Dispatch authorisation requested. A different owner or administrator must review this exact ready shipment.');
    }
    public function review(Request $request,int $shipment,string $decision,
        ShipmentDispatchApproval $service):RedirectResponse
    {
        abort_unless(in_array($decision,['approve','reject'],true),404);
        abort_unless(in_array($request->attributes->get('membership')->role,['owner','admin'],true),403);
        $data=$request->validate(['reason'=>['required','string','min:20','max:1000']]);
        $service->review($this->own($request,$shipment),$request->user()->id,
            $decision==='approve',$data['reason']);
        return back()->with('status',$decision==='approve'
            ?'Independent shipment dispatch approval recorded for this ready shipment.'
            :'Dispatch request rejected with a review reason.');
    }
}
