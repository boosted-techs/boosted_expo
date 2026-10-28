<div class="form-stack">
@if(isset($orders))
<label class="field"><span>Confirmed sales order (optional)</span>
<select name="sales_order_id"><option value="">Unlinked shipment / operational only</option>
@foreach($orders as $order)<option value="{{ $order->id }}" @selected(old('sales_order_id',$prefillOrderId??null)==$order->id)>{{ $order->reference }} · {{ $order->customer->name }} · {{ $order->currency }} {{ $order->grand_total }}</option>@endforeach
</select><small>Link the shipment to an accepted quotation for quantity reconciliation and commercial invoicing. The customer must match the order.</small></label>
@endif
<div class="form-columns">
<label class="field"><span>Buyer / consignee</span>
<select name="customer_id" required><option value="">Choose customer</option>
@foreach($customers as $customer)
<option value="{{ $customer->id }}" @selected(old('customer_id',$shipment?->customer_id ?? ($prefillCustomerId ?? null))==$customer->id)>{{ $customer->name }} — {{ $customer->country_code }}</option>
@endforeach</select></label>
<label class="field"><span>Transport mode</span><input value="Air freight" disabled><small>Air shipments are supported in this release.</small></label>
</div>
<div class="form-columns">
<label class="field"><span>Origin country (2 letters)</span><input name="origin_country" value="{{ old('origin_country',$shipment?->origin_country ?? $business->country_code) }}" minlength="2" maxlength="2" required></label>
<label class="field"><span>Destination country (2 letters)</span><input name="destination_country" value="{{ old('destination_country',$shipment?->destination_country ?? '') }}" minlength="2" maxlength="2" placeholder="AE" required></label>
</div>
<div class="form-columns">
<label class="field"><span>Origin airport / station</span><input name="origin_airport" maxlength="90" value="{{ old('origin_airport',$shipment?->origin_airport) }}" placeholder="e.g. Entebbe International (EBB)"></label>
<label class="field"><span>Destination airport / station</span><input name="destination_airport" maxlength="90" value="{{ old('destination_airport',$shipment?->destination_airport) }}" placeholder="e.g. Dubai International (DXB)"></label>
</div>
<div class="form-columns">
<label class="field"><span>Airline / carrier</span><input name="carrier" maxlength="150" value="{{ old('carrier',$shipment?->carrier) }}" placeholder="Carrier name"></label>
<label class="field"><span>Flight number</span><input name="flight_number" maxlength="60" value="{{ old('flight_number',$shipment?->flight_number) }}" placeholder="e.g. EK730"></label>
</div>
<label class="field"><span>Air waybill number (AWB)</span><input name="air_waybill" maxlength="80" value="{{ old('air_waybill',$shipment?->air_waybill) }}" placeholder="As shown on the airline AWB"><small>Use the actual AWB supplied by the carrier. The system does not allocate or verify an AWB with an airline.</small></label>
<div class="form-columns">
<label class="field"><span>Scheduled departure</span><input name="scheduled_departure_at" type="datetime-local" value="{{ old('scheduled_departure_at',$shipment?->scheduled_departure_at?->copy()->timezone($business->timezone)->format('Y-m-d\TH:i')) }}"></label>
<label class="field"><span>Estimated arrival (optional)</span><input name="estimated_arrival_at" type="datetime-local" value="{{ old('estimated_arrival_at',$shipment?->estimated_arrival_at?->copy()->timezone($business->timezone)->format('Y-m-d\TH:i')) }}"></label>
</div>
<p class="form-hint">Enter departure and arrival times in your company's timezone: {{ $business->timezone }}.</p>
<label class="field"><span>Delivery term (optional)</span><select name="incoterm">
<option value="">Not agreed / leave blank</option>
@foreach(['EXW','FCA','CPT','CIP','DAP','DPU','DDP'] as $term)
<option value="{{ $term }}" @selected(old('incoterm',$shipment?->incoterm)===$term)>{{ $term }}</option>
@endforeach</select><small>Record the term agreed with the customer; do not invent one.</small></label>
<label class="field"><span>Shipment notes (optional)</span><textarea name="notes" rows="3" maxlength="2500">{{ old('notes',$shipment?->notes) }}</textarea></label>
<p class="form-hint">Save a draft now. Add flight details, AWB, departure date and a completed carton before marking ready.</p>
</div>