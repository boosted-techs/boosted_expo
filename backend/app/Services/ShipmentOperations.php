<?php

namespace App\Services;

use App\Models\Carton;
use App\Models\ExportShipment;
use App\Models\ShipmentCarton;
use App\Models\ShipmentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShipmentOperations
{
    private function lock(ExportShipment $shipment): ExportShipment
    {
        return ExportShipment::query()->where('business_id',$shipment->business_id)
            ->lockForUpdate()->findOrFail($shipment->id);
    }

    private function draft(ExportShipment $shipment): void
    {
        abort_unless($shipment->status === 'draft',422,
            'Only draft shipments can be changed. Reopen a ready shipment before editing.');
    }

    private function event(ExportShipment $shipment, int $userId, string $action, array $details=[]): void
    {
        ShipmentEvent::create([
            'business_id'=>$shipment->business_id, 'shipment_id'=>$shipment->id,
            'actor_id'=>$userId, 'action'=>$action,'details'=>$details,'created_at'=>now(),
        ]);
    }

    public function create(int $businessId, array $data, int $userId): ExportShipment
    {
        return DB::transaction(function () use ($businessId,$data,$userId) {
            $existing=ExportShipment::query()->where('business_id',$businessId)
                ->where('creation_key',$data['creation_key'])->first();
            if ($existing) return $existing;
            $shipment=ExportShipment::create([
                ...$data,'business_id'=>$businessId,
                'reference'=>'SHP-'.Str::upper((string)Str::ulid()),
                'transport_mode'=>'air','status'=>'draft','created_by'=>$userId,
            ]);
            $this->event($shipment,$userId,'created');
            return $shipment;
        });
    }

    public function updateDraft(ExportShipment $shipment, array $data, int $userId): ExportShipment
    {
        return DB::transaction(function () use ($shipment,$data,$userId) {
            $shipment=$this->lock($shipment);
            $this->draft($shipment);
            $changes=[];
            foreach ($data as $key=>$value) {
                if ((string)($shipment->{$key} ?? '') !== (string)($value ?? '')) {
                    $changes[$key]=['before'=>$shipment->{$key},'after'=>$value];
                }
            }
            if($shipment->sales_order_id && (int)($data['customer_id']??$shipment->customer_id)!==$shipment->customer_id) {
                throw ValidationException::withMessages(['customer_id'=>'Customer must match the linked sales order.']);
            }
            if($shipment->sales_order_id && !empty($data['incoterm'])) {
                $order=\App\Models\SalesOrder::query()->where('business_id',$shipment->business_id)
                    ->findOrFail($shipment->sales_order_id);
                if($order->incoterm && $order->incoterm!==$data['incoterm']) {
                    throw ValidationException::withMessages(['incoterm'=>'Shipment term must match the accepted sales order.']);
                }
            }
            $shipment->update($data);
            if ($changes !== []) $this->event($shipment,$userId,'updated',['fields'=>array_keys($changes)]);
            return $shipment;
        });
    }

    public function allocate(ExportShipment $shipment, array $cartonIds, int $userId): int
    {
        return DB::transaction(function () use ($shipment,$cartonIds,$userId) {
            $shipment=$this->lock($shipment);
            $this->draft($shipment);
            $cartonIds=array_values(array_unique(array_map('intval',$cartonIds)));
            $cartons=Carton::query()->where('business_id',$shipment->business_id)
                ->whereIn('id',$cartonIds)->with('packingJob')
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($cartons->count() === count($cartonIds),404,
                'One or more cartons are not part of this business.');

            $allocated=ShipmentCarton::query()->where('business_id',$shipment->business_id)
                ->whereIn('carton_id',$cartonIds)->get()->keyBy('carton_id');
            foreach ($cartons as $carton) {
                $existing=$allocated->get($carton->id);
                if ($existing) {
                    if ($existing->shipment_id === $shipment->id) continue;
                    throw ValidationException::withMessages([
                        'carton_ids'=>'Carton '.$carton->carton_code.' is allocated to another shipment.',
                    ]);
                }
                if ($carton->contents()->whereHas('batch',
                    fn($q)=>$q->where('is_quarantined',true))->exists()) {
                    throw ValidationException::withMessages(['carton_ids'=>
                        'Carton '.$carton->carton_code.' contains quality-held produce and cannot be allocated.']);
                }
                if ($carton->status !== 'packed' || $carton->packingJob?->status !== 'completed') {
                    throw ValidationException::withMessages([
                        'carton_ids'=>'Carton '.$carton->carton_code.' must be packed and its packing job completed.',
                    ]);
                }
            }

            if($shipment->sales_order_id) {
                $order=\App\Models\SalesOrder::query()->where('business_id',$shipment->business_id)
                    ->lockForUpdate()->findOrFail($shipment->sales_order_id);
                $incoming=$cartons->filter(fn($carton)=>!$allocated->has($carton->id));
                $incoming->each(fn($carton)=>$carton->load('contents'));
                app(SalesFulfillment::class)->assertCapacity($order,$incoming->all());
            }
            $added=0;
            foreach ($cartons as $carton) {
                if ($allocated->has($carton->id)) continue;
                ShipmentCarton::create([
                    'business_id'=>$shipment->business_id,'shipment_id'=>$shipment->id,
                    'carton_id'=>$carton->id,'allocated_by'=>$userId,
                ]);
                $carton->update(['status'=>'reserved']);
                $added++;
            }
            if ($added>0) $this->event($shipment,$userId,'cartons_allocated',[
                'added'=>$added,'carton_codes'=>$cartons->pluck('carton_code')->values()->all(),
            ]);
            return $added;
        },3);
    }

    private function assertNoHeldProduce(ExportShipment $shipment):void
    {
        $cartonIds=$shipment->allocations()->pluck('carton_id');
        if(Carton::query()->where('business_id',$shipment->business_id)
            ->whereIn('id',$cartonIds)->whereHas('contents.batch',
                fn($q)=>$q->where('is_quarantined',true))->exists()){
            throw ValidationException::withMessages(['carton_ids'=>
                'This shipment contains produce under quality hold. A responsible reviewer must resolve the batch hold before dispatch.']);
        }
    }

    public function release(ExportShipment $shipment, int $cartonId, int $userId): void
    {
        DB::transaction(function () use ($shipment,$cartonId,$userId) {
            $shipment=$this->lock($shipment);
            $this->draft($shipment);
            $allocation=ShipmentCarton::query()->where('business_id',$shipment->business_id)
                ->where('shipment_id',$shipment->id)->where('carton_id',$cartonId)
                ->lockForUpdate()->firstOrFail();
            $carton=Carton::query()->where('business_id',$shipment->business_id)
                ->lockForUpdate()->findOrFail($cartonId);
            abort_unless($carton->status==='reserved',422,'Only reserved cartons can be released.');
            $allocation->delete();
            $carton->update(['status'=>'packed']);
            $this->event($shipment,$userId,'carton_released',['carton_code'=>$carton->carton_code]);
        },3);
    }

    public function ready(ExportShipment $shipment, int $userId): void
    {
        DB::transaction(function () use ($shipment,$userId) {
            $shipment=$this->lock($shipment);
            $this->draft($shipment);
            foreach (['origin_airport','destination_airport','flight_number','air_waybill',
                'scheduled_departure_at'] as $field) {
                if (! filled($shipment->{$field})) {
                    throw ValidationException::withMessages([
                        $field=>'Complete '.$field.' before marking a shipment ready.',
                    ]);
                }
            }
            $allocations=$shipment->allocations()->with('carton.packingJob')->get();
            if ($allocations->isEmpty()) {
                throw ValidationException::withMessages([
                    'carton_ids'=>'Allocate at least one completed carton before marking ready.',
                ]);
            }
            foreach ($allocations as $line) {
                if ($line->carton?->status !== 'reserved' ||
                    $line->carton?->packingJob?->status !== 'completed') {
                    throw ValidationException::withMessages([
                        'carton_ids'=>'The shipment contains an unavailable carton.',
                    ]);
                }
            }
            if($shipment->sales_order_id) {
                $order=\App\Models\SalesOrder::query()->where('business_id',$shipment->business_id)
                    ->lockForUpdate()->findOrFail($shipment->sales_order_id);
                app(SalesFulfillment::class)->assertCapacity($order,[]);
            }
            $this->assertNoHeldProduce($shipment);
            $shipment->update(['status'=>'ready']);
            $this->event($shipment,$userId,'marked_ready',['carton_count'=>$allocations->count()]);
        });
    }

    public function reopen(ExportShipment $shipment, int $userId): void
    {
        DB::transaction(function () use ($shipment,$userId) {
            $shipment=$this->lock($shipment);
            abort_unless($shipment->status==='ready',422,'Only a ready shipment can be reopened.');
            abort_unless(! $shipment->commercialInvoice()->exists(),422,
                'A commercial invoice has been issued. A controlled correction/credit workflow is required before changing this shipment.');
            $shipment->update(['status'=>'draft']);
            $this->event($shipment,$userId,'reopened',[
                'warning'=>'Documents issued before reopening may not match the revised shipment.',
            ]);
        });
    }

    public function dispatch(ExportShipment $shipment, string $actualDeparture, int $userId): void
    {
        DB::transaction(function () use ($shipment,$actualDeparture,$userId) {
            $shipment=$this->lock($shipment);
            abort_unless($shipment->status==='ready',422,'Mark the shipment ready before dispatch.');
            $this->assertNoHeldProduce($shipment);
            app(ShipmentDispatchApproval::class)->assertDispatchAllowed($shipment,$userId);
            $allocations=$shipment->allocations()->orderBy('carton_id')
                ->lockForUpdate()->get();
            if ($allocations->isEmpty()) {
                throw ValidationException::withMessages(['dispatch'=>'This shipment has no allocated cartons.']);
            }
            $cartons=Carton::query()->where('business_id',$shipment->business_id)
                ->whereIn('id',$allocations->pluck('carton_id'))->orderBy('id')
                ->lockForUpdate()->get();
            abort_unless($cartons->count()===$allocations->count() &&
                $cartons->every(fn ($carton)=>$carton->status==='reserved'),422,
                'All allocated cartons must remain reserved until dispatch.');
            foreach ($cartons as $carton) $carton->update(['status'=>'shipped']);
            $shipment->update([
                'status'=>'dispatched','actual_departure_at'=>$actualDeparture,
                'dispatched_by'=>$userId,
            ]);
            $this->event($shipment,$userId,'dispatched',[
                'carton_count'=>$allocations->count(),
                'actual_departure_at'=>$actualDeparture,
                'recorded_manually'=>true,
            ]);
        },3);
    }

    public function arrive(ExportShipment $shipment, string $arrivalTime, int $userId): void
    {
        DB::transaction(function () use ($shipment,$arrivalTime,$userId) {
            $shipment=$this->lock($shipment);
            abort_unless($shipment->status==='dispatched',422,'Only a dispatched shipment can be marked delivered.');
            if (strtotime($arrivalTime) < strtotime((string)$shipment->actual_departure_at)) {
                throw ValidationException::withMessages([
                    'actual_arrival_at'=>'Arrival must not be earlier than the recorded departure.',
                ]);
            }
            $cartons=Carton::query()->where('business_id',$shipment->business_id)
                ->whereIn('id',$shipment->allocations()->pluck('carton_id'))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($cartons as $carton) $carton->update(['status'=>'delivered']);
            $shipment->update([
                'status'=>'delivered','actual_arrival_at'=>$arrivalTime,'delivered_by'=>$userId,
            ]);
            $this->event($shipment,$userId,'delivered',[
                'actual_arrival_at'=>$arrivalTime,'recorded_manually'=>true,
            ]);
        });
    }

    public function cancel(ExportShipment $shipment, int $userId): void
    {
        DB::transaction(function () use ($shipment,$userId) {
            $shipment=$this->lock($shipment);
            abort_unless(in_array($shipment->status,['draft','ready'],true),422,
                'Dispatched shipments cannot be cancelled through this workflow.');
            abort_unless(! $shipment->commercialInvoice()->exists(),422,
                'This shipment already has a commercial invoice and cannot be cancelled here.');
            $allocations=$shipment->allocations()->orderBy('carton_id')->lockForUpdate()->get();
            $cartons=Carton::query()->where('business_id',$shipment->business_id)
                ->whereIn('id',$allocations->pluck('carton_id'))->orderBy('id')
                ->lockForUpdate()->get();
            abort_unless($cartons->every(fn ($c)=>$c->status==='reserved'),422,
                'Cannot release carton(s) with an unexpected status.');
            foreach ($cartons as $carton) $carton->update(['status'=>'packed']);
            $shipment->allocations()->delete();
            $shipment->update(['status'=>'cancelled']);
            $this->event($shipment,$userId,'cancelled',[
                'cartons_released'=>$cartons->count(),
                'warning'=>'Previously issued documents remain in history.',
            ]);
        });
    }
}
