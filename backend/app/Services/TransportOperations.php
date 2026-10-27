<?php

namespace App\Services;

use App\Models\ExportShipment;
use App\Models\ShipmentCarton;
use App\Models\ShipmentEvent;
use App\Models\TransportHandover;
use App\Models\TransportLeg;
use App\Models\TransportPartner;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransportOperations
{
    public function plan(ExportShipment $shipment, array $data, int $actor): TransportLeg
    {
        return DB::transaction(function () use ($shipment, $data, $actor) {
            app(OrderOperations::class)->lockForWork($shipment->business_id, 'exports');
            $shipment->refresh();
            $old = TransportLeg::query()->where('business_id', $shipment->business_id)->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_unless($old->shipment_id === $shipment->id && $old->sequence === (int) $data['sequence'], 409, 'Transport key was used for another leg.');

                return $old;
            }
            abort_if(in_array($shipment->status, ['cancelled', 'delivered'], true), 422, 'This shipment cannot accept a new transport plan.');
            $partner = TransportPartner::query()->where('business_id', $shipment->business_id)->where('active', true)->findOrFail($data['transport_partner_id']);
            app(OrderOperations::class)->person($shipment->business_id, (int) $data['staff_person_id']);
            $cartons = array_map('intval', $data['carton_ids']);
            sort($cartons);
            $count = ShipmentCarton::query()->where('business_id', $shipment->business_id)->where('shipment_id', $shipment->id)->whereIn('carton_id', $cartons)->count();
            abort_unless($count === count($cartons), 404);
            abort_unless($count === ShipmentCarton::query()->where('business_id', $shipment->business_id)->where('shipment_id', $shipment->id)->count(), 422, 'Include the whole shipment manifest; plan separate shipments for different routes.');
            $maxSequence = (int) TransportLeg::query()->where('business_id', $shipment->business_id)->where('shipment_id', $shipment->id)->max('sequence');
            $last = TransportLeg::query()->where('business_id', $shipment->business_id)->where('shipment_id', $shipment->id)->where('status', '!=', 'cancelled')->orderByDesc('sequence')->first();
            abort_unless((int) $data['sequence'] === $maxSequence + 1, 422, 'Add transport legs in consecutive journey order.');
            $due = Carbon::parse($data['due_at'], 'Africa/Kampala')->utc();
            if ($last) {
                abort_unless($data['origin'] === $last->destination && $last->carton_ids === $cartons && $due->gte($last->due_at), 422, 'The next leg must continue the same carton manifest from the previous destination, in time order.');
            }

            return TransportLeg::create([...$data, 'business_id' => $shipment->business_id, 'shipment_id' => $shipment->id, 'partner_snapshot' => $partner->only(['partner_code', 'name', 'phone']), 'carton_ids' => $cartons, 'due_at' => $due, 'created_by' => $actor]);
        }, 3);
    }

    public function handover(TransportLeg $leg, array $data, int $actor): void
    {
        DB::transaction(function () use ($leg, $data, $actor) {
            app(OrderOperations::class)->lockForWork($leg->business_id, 'exports');
            $leg->refresh();
            $old = TransportHandover::query()->where('business_id', $leg->business_id)->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_unless($old->transport_leg_id === $leg->id && $old->action === $data['action'] && $old->evidence_reference === $data['evidence_reference'], 409, 'Handover key was already used.');

                return;
            }
            $shipment = ExportShipment::query()->where('business_id', $leg->business_id)->findOrFail($leg->shipment_id);
            abort_if($shipment->status === 'cancelled', 422, 'This shipment is cancelled.');
            $expected = match ($leg->status) {
                'planned' => 'collected','collected' => 'handed_over',default => null
            };
            abort_unless($expected === $data['action'], 422, 'Record collection before the final handover; completed evidence cannot be overwritten.');
            if ($previousLeg = TransportLeg::query()->where('shipment_id', $leg->shipment_id)->where('sequence', '<', $leg->sequence)->where('status', '!=', 'cancelled')->orderByDesc('sequence')->first()) {
                abort_unless($previousLeg->status === 'handed_over', 422, 'Record the previous leg handover first.');
            }
            $occurred = Carbon::parse($data['occurred_at'], 'Africa/Kampala')->utc();
            $previous = TransportHandover::query()->where('business_id', $leg->business_id)->whereIn('transport_leg_id', TransportLeg::query()->where('shipment_id', $leg->shipment_id)->where('sequence', '<=', $leg->sequence)->select('id'))->max('occurred_at');
            if ($occurred->isFuture() || ($previous && $occurred->lt(Carbon::parse($previous)))) {
                throw ValidationException::withMessages(['occurred_at' => 'Handover time must follow the previous evidence and cannot be in the future.']);
            }
            TransportHandover::create([...$data, 'business_id' => $leg->business_id, 'transport_leg_id' => $leg->id, 'occurred_at' => $occurred, 'recorded_by' => $actor]);
            $leg->update(['status' => $data['action']]);
            ShipmentEvent::create(['business_id' => $leg->business_id, 'shipment_id' => $leg->shipment_id, 'actor_id' => $actor, 'action' => 'transport_'.$data['action'], 'details' => ['leg_id' => $leg->id, 'evidence_reference' => $data['evidence_reference']], 'created_at' => now()]);
        }, 3);
    }
}
