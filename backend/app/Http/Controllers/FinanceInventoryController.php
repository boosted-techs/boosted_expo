<?php
namespace App\Http\Controllers;

use App\Models\ExportShipment;
use App\Models\FinanceInventoryRelief;
use App\Models\PackingWasteEntry;
use App\Services\FinanceInventoryValuation;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceInventoryController extends Controller
{
    private function check(Request $request,string $action):void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),$action),403);
    }
    public function index(Request $request):View
    {
        $this->check($request,'finance.inventory.view');
        $business=$request->attributes->get('activeBusiness');
        $reliefs=FinanceInventoryRelief::query()->where('business_id',$business->id)
            ->latest()->paginate(30);
        $shipments=ExportShipment::query()->where('business_id',$business->id)
            ->whereIn('status',['dispatched','delivered'])->with('customer')
            ->latest()->limit(75)->get();
        $wastes=PackingWasteEntry::query()->where('business_id',$business->id)
            ->with('batch')->orderByDesc('id')->limit(75)->get();
        $usedShipments=FinanceInventoryRelief::query()->where('business_id',$business->id)
            ->whereNotNull('shipment_id')->pluck('shipment_id')->all();
        $usedWaste=FinanceInventoryRelief::query()->where('business_id',$business->id)
            ->whereNotNull('waste_entry_id')->pluck('waste_entry_id')->all();
        $canPrepare=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'finance.inventory.prepare');
        return view('finance.inventory',compact(
            'business','reliefs','shipments','wastes','usedShipments','usedWaste','canPrepare',
        ));
    }
    public function prepare(Request $request,string $kind,int $id,
        FinanceInventoryValuation $valuation):RedirectResponse
    {
        $this->check($request,'finance.inventory.prepare');
        abort_unless(in_array($kind,['cogs','waste'],true),404);
        $record=$valuation->prepare($request->attributes->get('activeBusiness'),
            $kind,$id,$request->user()->id);
        return redirect()->route('finance.inventory.show',$record->id)->with('status',
            'Source-batch valuation captured; submit this document for independent approval and posting.');
    }
    public function show(Request $request,int $id):View
    {
        $this->check($request,'finance.inventory.view');
        $relief=FinanceInventoryRelief::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($id);
        return view('finance.inventory-detail',compact('relief'));
    }
}