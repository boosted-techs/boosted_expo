<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\ShipmentEvent;
use App\Models\TransportHandover;
use App\Models\TransportLeg;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransportPlanRevisionController extends Controller
{
    public function destroy(Request $request, int $leg): RedirectResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'shipments.manage'), 403);
        $business = $request->attributes->get('activeBusiness');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        DB::transaction(function () use ($business, $leg, $data, $request) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $record = TransportLeg::query()->where('business_id', $business->id)->findOrFail($leg);
            abort_unless($record->status === 'planned' && ! TransportHandover::query()->where('transport_leg_id', $record->id)->exists(), 422, 'A leg with custody evidence cannot be cancelled here.');
            abort_if(TransportLeg::query()->where('shipment_id', $record->shipment_id)->where('sequence', '>', $record->sequence)->where('status', '!=', 'cancelled')->exists(), 422, 'Cancel later unused legs first.');
            $record->update(['status' => 'cancelled']);
            ShipmentEvent::create(['business_id' => $business->id, 'shipment_id' => $record->shipment_id, 'actor_id' => $request->user()->id, 'action' => 'transport_plan_cancelled', 'details' => ['leg_id' => $record->id, 'reason' => $data['reason']], 'created_at' => now()]);
        });

        return back()->with('status', 'Unused leg cancelled with its original plan retained. Add a replacement leg if needed.');
    }
}
