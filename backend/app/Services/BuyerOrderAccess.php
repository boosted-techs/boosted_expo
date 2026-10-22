<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessOperatingPolicy;
use App\Models\BuyerOrderLink;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BuyerOrderAccess
{
    public function invite(SalesOrder $order,int $actor):BuyerOrderLink
    {
        $plain=Str::random(64);
        $record=DB::transaction(function()use($order,$actor,$plain){
            Business::query()->whereKey($order->business_id)->lockForUpdate()->firstOrFail();
            $order=SalesOrder::query()->where('business_id',$order->business_id)
                ->lockForUpdate()->with('customer')->findOrFail($order->id);
            $email=$order->customer?->email;
            if(!$order->customer?->active || !filter_var($email,FILTER_VALIDATE_EMAIL)){
                throw ValidationException::withMessages(['customer'=>
                    'Set an active buyer with a valid email address before sharing private order tracking.']);
            }
            BuyerOrderLink::query()->where('business_id',$order->business_id)
                ->where('sales_order_id',$order->id)->whereNull('revoked_at')
                ->update(['revoked_at'=>now(),'revoked_by'=>$actor]);
            $days=(int)(BusinessOperatingPolicy::query()->where('business_id',$order->business_id)
                ->value('buyer_link_days')??7);
            return BuyerOrderLink::create([
                'business_id'=>$order->business_id,'sales_order_id'=>$order->id,
                'buyer_email'=>strtolower(trim($email)),
                'token_hash'=>hash('sha256',$plain),
                'expires_at'=>now()->addDays(min(30,max(1,$days))),
                'created_by'=>$actor,
            ]);
        },3);
        try {
            Mail::to($record->buyer_email)->send(new \App\Mail\BuyerOrderTrackingMail(
                $order->reference,$plain,$record->expires_at->toDayDateTimeString()
            ));
        } catch(\Throwable) {
            $record->update(['revoked_at'=>now(),'revoked_by'=>$actor]);
            throw ValidationException::withMessages(['customer'=>
                'The tracking email could not be delivered to the configured email service. The link has been disabled.']);
        }
        return $record;
    }

    public function revoke(int $businessId,int $orderId,int $linkId,int $actor):void
    {
        DB::transaction(function()use($businessId,$orderId,$linkId,$actor){
            Business::query()->whereKey($businessId)->lockForUpdate()->firstOrFail();
            $link=BuyerOrderLink::query()->where('business_id',$businessId)
                ->where('sales_order_id',$orderId)->lockForUpdate()->findOrFail($linkId);
            if($link->revoked_at!==null){
                throw ValidationException::withMessages(['link'=>'This link has already been revoked.']);
            }
            $link->update(['revoked_at'=>now(),'revoked_by'=>$actor]);
        },3);
    }

    public function resolve(string $token):SalesOrder
    {
        if(!preg_match('/^[A-Za-z0-9]{64}$/D',$token))abort(404);
        $record=BuyerOrderLink::query()->where('token_hash',hash('sha256',$token))
            ->whereNull('revoked_at')->where('expires_at','>',now())
            ->with('order.customer')->firstOrFail();
        $order=$record->order;
        abort_unless($order && $order->business_id===$record->business_id
            && $order->customer?->active
            && strtolower(trim((string)$order->customer->email))===$record->buyer_email
            && $order->status==='confirmed'
            && Business::query()->whereKey($order->business_id)
                ->where('is_active',true)->exists(),404);
        return $order->load(['customer','lines','shipments']);
    }
}
