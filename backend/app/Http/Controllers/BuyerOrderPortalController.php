<?php

namespace App\Http\Controllers;

use App\Models\BuyerOrderLink;
use App\Models\SalesOrder;
use App\Services\BuyerOrderAccess;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BuyerOrderPortalController extends Controller
{
    private function own(Request $request,int $order):SalesOrder
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'sales.manage'),403);
        return SalesOrder::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($order);
    }

    public function share(Request $request,int $order,BuyerOrderAccess $access):RedirectResponse
    {
        $record=$access->invite($this->own($request,$order),$request->user()->id);
        return back()->with('status',config('mail.default')==='log'
            ?'Buyer access was recorded in the local development mail log, not delivered externally.'
            :'A private, time-limited order tracking link was sent to '.$record->buyer_email.'.');
    }

    public function revoke(Request $request,int $order,int $link,BuyerOrderAccess $access):RedirectResponse
    {
        $order=$this->own($request,$order);
        $access->revoke($order->business_id,$order->id,$link,$request->user()->id);
        return back()->with('status','Buyer tracking access revoked immediately.');
    }

    public function show(string $token,BuyerOrderAccess $access):View
    {
        $order=$access->resolve($token);
        return view('buyer.order',compact('order'));
    }
}
