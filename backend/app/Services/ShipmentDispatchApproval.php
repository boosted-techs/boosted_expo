<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessOperatingPolicy;
use App\Models\ExportShipment;
use App\Models\ShipmentDispatchReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentDispatchApproval
{
    public function required(int $businessId):bool
    {
        return (bool) BusinessOperatingPolicy::query()->where('business_id',$businessId)
            ->value('dispatch_approval_required');
    }

    private function fingerprint(ExportShipment $shipment):array
    {
        abort_unless($shipment->status==='ready',422,'Mark the shipment ready before requesting dispatch approval.');
        $readyEvent=$shipment->events()->where('action','marked_ready')->orderByDesc('id')->firstOrFail();
        $cartons=$shipment->allocations()->orderBy('carton_id')
            ->pluck('carton_id')->map(fn($id)=>(int)$id)->all();
        abort_if($cartons===[],422,'Cannot approve an empty shipment.');
        $facts=[
            'business_id'=>$shipment->business_id,'shipment_id'=>$shipment->id,
            'ready_event_id'=>$readyEvent->id,'carton_ids'=>$cartons,
            'flight_number'=>$shipment->flight_number,'air_waybill'=>$shipment->air_waybill,
            'origin_airport'=>$shipment->origin_airport,
            'destination_airport'=>$shipment->destination_airport,
            'scheduled_departure_at'=>$shipment->scheduled_departure_at?->toIso8601String(),
            'customer_id'=>$shipment->customer_id,'sales_order_id'=>$shipment->sales_order_id,
        ];
        return [$readyEvent->id,hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR))];
    }

    public function latest(ExportShipment $shipment):?ShipmentDispatchReview
    {
        if($shipment->status!=='ready')return null;
        [$event]=$this->fingerprint($shipment);
        return ShipmentDispatchReview::query()->where('business_id',$shipment->business_id)
            ->where('shipment_id',$shipment->id)->where('ready_event_id',$event)->first();
    }

    public function request(ExportShipment $shipment,int $actor):ShipmentDispatchReview
    {
        return DB::transaction(function()use($shipment,$actor){
            Business::query()->whereKey($shipment->business_id)->lockForUpdate()->firstOrFail();
            $shipment=ExportShipment::query()->where('business_id',$shipment->business_id)
                ->lockForUpdate()->findOrFail($shipment->id);
            abort_unless($this->required($shipment->business_id),422,
                'Your company has not enabled independent dispatch approval.');
            [$event,$hash]=$this->fingerprint($shipment);
            $existing=ShipmentDispatchReview::query()->where('business_id',$shipment->business_id)
                ->where('shipment_id',$shipment->id)->where('ready_event_id',$event)->first();
            if($existing && $existing->status!=='rejected')return $existing;
            if($existing){
                throw ValidationException::withMessages(['approval'=>
                    'This ready-shipment review was rejected. Reopen the shipment, resolve the issues, then mark it ready again before requesting new approval.']);
            }
            return ShipmentDispatchReview::create([
                'business_id'=>$shipment->business_id,'shipment_id'=>$shipment->id,
                'ready_event_id'=>$event,'snapshot_sha256'=>$hash,
                'status'=>'pending','requested_by'=>$actor,
            ]);
        },3);
    }

    public function review(ExportShipment $shipment,int $actor,bool $approved,string $reason):void
    {
        DB::transaction(function()use($shipment,$actor,$approved,$reason){
            Business::query()->whereKey($shipment->business_id)->lockForUpdate()->firstOrFail();
            $shipment=ExportShipment::query()->where('business_id',$shipment->business_id)
                ->lockForUpdate()->findOrFail($shipment->id);
            abort_unless($this->required($shipment->business_id),422,
                'Independent dispatch approvals are not enabled for this business.');
            [$event,$hash]=$this->fingerprint($shipment);
            $record=ShipmentDispatchReview::query()->where('business_id',$shipment->business_id)
                ->where('shipment_id',$shipment->id)->where('ready_event_id',$event)
                ->lockForUpdate()->firstOrFail();
            if($record->status!=='pending' || $record->requested_by===$actor
                || !hash_equals($record->snapshot_sha256,$hash)){
                throw ValidationException::withMessages(['approval'=>
                    'Only another authorised reviewer may approve the unchanged, pending shipment.']);
            }
            $record->update([
                'status'=>$approved?'approved':'rejected','reviewed_by'=>$actor,
                'review_note'=>$reason,'reviewed_at'=>now(),
            ]);
        },3);
    }

    public function assertDispatchAllowed(ExportShipment $shipment,int $dispatcher):void
    {
        if(!$this->required($shipment->business_id))return;
        [$event,$hash]=$this->fingerprint($shipment);
        $record=ShipmentDispatchReview::query()->where('business_id',$shipment->business_id)
            ->where('shipment_id',$shipment->id)->where('ready_event_id',$event)->first();
        if(!$record || $record->status!=='approved'
            || !hash_equals($record->snapshot_sha256,$hash)
            || $record->requested_by===$record->reviewed_by
            || !$record->reviewed_at){
            throw ValidationException::withMessages(['dispatch'=>
                'An independent management approval is required for this exact shipment before dispatch.']);
        }
    }
}
