<?php

namespace App\Services;

use App\Models\Carton;
use App\Models\ExportShipment;
use App\Models\SalesOrder;
use App\Models\ShipmentCarton;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesFulfillment
{
    public function allocated(SalesOrder $order): array
    {
        $shipmentIds=ExportShipment::query()->where('business_id',$order->business_id)
            ->where('sales_order_id',$order->id)->where('status','!=','cancelled')->pluck('id');
        $allocations=ShipmentCarton::query()->where('business_id',$order->business_id)
            ->whereIn('shipment_id',$shipmentIds)
            ->with('carton.packingJob','carton.contents')->get();
        $values=[];
        foreach($allocations as $line) {
            $carton=$line->carton;
            if(!$carton || !$carton->packingJob) continue;
            $productId=$carton->packingJob->product_id;
            foreach($carton->contents as $content) {
                $values[$productId]=($values[$productId]??BigDecimal::zero())
                    ->plus((string)$content->quantity);
            }
        }
        return $values;
    }

    public function assertCapacity(SalesOrder $order, array $additionalCartons): void
    {
        abort_unless($order->status==='confirmed',422,'This sales order cannot accept more cartons.');
        $expected=$order->lines()->get()->keyBy('product_id');
        $total=$this->allocated($order);
        foreach($additionalCartons as $carton) {
            if($carton->business_id!==$order->business_id || !$carton->packingJob) {
                abort(404,'Carton is not part of this business.');
            }
            $productId=$carton->packingJob->product_id;
            if(!$expected->has($productId)) {
                throw ValidationException::withMessages([
                    'carton_ids'=>'Carton '.$carton->carton_code.' contains a product not on this sales order.',
                ]);
            }
            foreach($carton->contents as $content) {
                $total[$productId]=($total[$productId]??BigDecimal::zero())
                    ->plus((string)$content->quantity);
            }
        }
        foreach($total as $id=>$qty) {
            if(! $expected->has($id) || $qty->isGreaterThan(
                BigDecimal::of((string)$expected[$id]->quantity))) {
                throw ValidationException::withMessages([
                    'carton_ids'=>'Carton allocation exceeds the ordered quantity for a product.',
                ]);
            }
        }
    }

    public function linkShipment(ExportShipment $shipment, SalesOrder $order, int $userId): void
    {
        DB::transaction(function()use($shipment,$order,$userId) {
            $shipment=ExportShipment::query()->where('business_id',$shipment->business_id)
                ->lockForUpdate()->findOrFail($shipment->id);
            abort_unless($shipment->status==='draft',422,
                'Only draft shipments can be connected to customer orders.');
            $order=SalesOrder::query()->where('business_id',$shipment->business_id)
                ->lockForUpdate()->findOrFail($order->id);
            abort_unless($order->status==='confirmed',422,'This order is not open.');
            abort_unless($shipment->customer_id===$order->customer_id,422,
                'Shipment consignee must match the order customer.');
            if($shipment->incoterm && $order->incoterm && $shipment->incoterm!==$order->incoterm) {
                throw ValidationException::withMessages(['incoterm'=>'Shipment delivery term differs from the accepted sales order.']);
            }
            abort_unless($shipment->sales_order_id===null || $shipment->sales_order_id===$order->id,422,
                'Shipment already belongs to another sales order.');
            if($shipment->sales_order_id===$order->id) return;

            $cartons=Carton::query()->where('business_id',$shipment->business_id)
                ->whereIn('id',$shipment->allocations()->pluck('carton_id'))
                ->with(['packingJob','contents'])->orderBy('id')->lockForUpdate()->get();
            $this->assertCapacity($order,$cartons->all());
            $shipment->update(['sales_order_id'=>$order->id]);
            \App\Models\ShipmentEvent::create([
                'business_id'=>$shipment->business_id,'shipment_id'=>$shipment->id,
                'actor_id'=>$userId,'action'=>'sales_order_linked',
                'details'=>['sales_order_reference'=>$order->reference],
                'created_at'=>now(),
            ]);
        },3);
    }

    public function summary(SalesOrder $order): array
    {
        $allocated=$this->allocated($order);
        $dispatched=[];
        $delivered=[];
        $shipments=$order->shipments()->where('status','!=','cancelled')
            ->with('allocations.carton.packingJob','allocations.carton.contents')->get();
        foreach($shipments as $shipment) {
            if(! in_array($shipment->status,['dispatched','delivered'],true)) continue;
            foreach($shipment->allocations as $allocation) {
                $carton=$allocation->carton;
                if(!$carton?->packingJob) continue;
                $key=$carton->packingJob->product_id;
                foreach($carton->contents as $content) {
                    $dispatched[$key]=($dispatched[$key]??BigDecimal::zero())->plus((string)$content->quantity);
                    if($shipment->status==='delivered') {
                        $delivered[$key]=($delivered[$key]??BigDecimal::zero())->plus((string)$content->quantity);
                    }
                }
            }
        }
        $lines=[];
        foreach($order->lines as $line) {
            $id=$line->product_id;
            $ordered=BigDecimal::of((string)$line->quantity);
            $booked=$allocated[$id]??BigDecimal::zero();
            $lines[]=[
                'product'=>$line->product,'product_name'=>$line->product_name,'ordered'=>(string)$ordered,
                'allocated'=>(string)$booked,'remaining'=>(string)$ordered->minus($booked),
                'dispatched'=>(string)($dispatched[$id]??BigDecimal::zero()),
                'delivered'=>(string)($delivered[$id]??BigDecimal::zero()),
                'unit_price'=>$line->unit_price,
            ];
        }
        return ['lines'=>$lines,'shipments'=>$shipments];
    }
}
