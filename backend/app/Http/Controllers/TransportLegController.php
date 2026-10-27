<?php

namespace App\Http\Controllers;

use App\Models\ExportShipment;
use App\Models\TransportLeg;
use App\Models\TransportPartner;
use App\Services\TransportOperations;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransportLegController extends Controller
{
    private function business(Request $request): int
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'shipments.manage'), 403);

        return $request->attributes->get('activeBusiness')->id;
    }

    public function partner(Request $request): RedirectResponse
    {
        $business = $this->business($request);
        $data = $request->validate(['partner_code' => ['required', 'alpha_dash', 'max:50', Rule::unique('transport_partners')->where('business_id', $business)], 'name' => ['required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:50']]);
        TransportPartner::create([...$data, 'business_id' => $business]);

        return back()->with('status', 'Transport partner saved.');
    }

    public function store(Request $request, int $shipment, TransportOperations $transport): RedirectResponse
    {
        $business = $this->business($request);
        $s = ExportShipment::query()->where('business_id', $business)->findOrFail($shipment);
        $data = $request->validate(['transport_partner_id' => ['required', 'integer'], 'staff_person_id' => ['required', 'integer'], 'sequence' => ['required', 'integer', 'between:1,100'], 'mode' => ['required', Rule::in(['road', 'air', 'sea', 'rail'])], 'origin' => ['required', 'string', 'max:150'], 'destination' => ['required', 'string', 'max:150'], 'due_at' => ['required', 'date_format:Y-m-d\TH:i'], 'driver_name' => ['nullable', 'string', 'max:150'], 'vehicle_reference' => ['nullable', 'string', 'max:100'], 'booking_reference' => ['nullable', 'string', 'max:100'], 'carton_ids' => ['required', 'array', 'min:1', 'max:1000'], 'carton_ids.*' => ['required', 'integer', 'distinct'], 'request_key' => ['required', 'uuid']]);
        $transport->plan($s, $data, $request->user()->id);

        return back()->with('status', 'Transport leg and carton manifest saved.');
    }

    public function handover(Request $request, int $leg, TransportOperations $transport): RedirectResponse
    {
        $b = $this->business($request);
        $record = TransportLeg::query()->where('business_id', $b)->findOrFail($leg);
        $data = $request->validate(['action' => ['required', Rule::in(['collected', 'handed_over'])], 'from_person' => ['required', 'string', 'max:150'], 'to_person' => ['required', 'string', 'max:150'], 'evidence_reference' => ['required', 'string', 'min:5', 'max:200'], 'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'], 'notes' => ['nullable', 'string', 'max:2000'], 'request_key' => ['required', 'uuid']]);
        $transport->handover($record, $data, $request->user()->id);

        return back()->with('status', 'Custody evidence recorded; this is not an airline or customs confirmation.');
    }
}
