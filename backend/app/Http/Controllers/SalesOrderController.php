<?php
namespace App\Http\Controllers;

use App\Models\ExportShipment;
use App\Models\SalesOrder;
use App\Services\SalesFulfillment;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesOrderController extends Controller
{
    private function check(Request $request,string $permission):void {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),$permission),403);
    }
    private function own(Request $request,int $id):SalesOrder {
        return SalesOrder::query()->where('business_id',$request->attributes
            ->get('activeBusiness')->id)->findOrFail($id);
    }
    public function index(Request $request):View {
        $this->check($request,'sales.orders.view');
        $orders=SalesOrder::query()->where('business_id',$request->attributes
            ->get('activeBusiness')->id)->with('customer')
            ->withCount('shipments')->latest()->paginate(20);
        return view('sales.orders.index',compact('orders'));
    }
    public function show(Request $request,int $order,SalesFulfillment $fulfillment):View {
        $this->check($request,'sales.orders.view');
        $order=$this->own($request,$order)->load(['customer','quotation','lines.product','invoices']);
        $summary=$fulfillment->summary($order);
        $draftShipments=ExportShipment::query()->where('business_id',$order->business_id)
            ->where('customer_id',$order->customer_id)->where('status','draft')
            ->whereNull('sales_order_id')->orderByDesc('id')->get();
        $canLink=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'shipments.manage');
        return view('sales.orders.show',compact('order','summary','draftShipments','canLink'));
    }
    public function link(Request $request,int $order,int $shipment,SalesFulfillment $fulfillment):RedirectResponse {
        $this->check($request,'shipments.manage');
        $order=$this->own($request,$order);
        $shipment=ExportShipment::query()->where('business_id',$order->business_id)
            ->findOrFail($shipment);
        $fulfillment->linkShipment($shipment,$order,$request->user()->id);
        return redirect()->route('sales-orders.show',$order->id)
            ->with('status','Shipment linked to the confirmed sales order.');
    }
}
