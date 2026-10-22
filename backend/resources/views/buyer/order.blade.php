@extends('layouts.public')
@section('title','Your Export Order')
@section('content')
<span class="eyebrow">PRIVATE ORDER TRACKING</span>
<h1>Order {{ $order->reference }}</h1>
<p class="subhead">Prepared for {{ $order->customer->name }}. This read-only page does not request payment or access to your accounts.</p>
<section class="section-card">
<div class="record"><strong>Order status</strong><span>{{ ucfirst($order->status) }}</span></div>
<div class="record"><strong>Confirmed total</strong><span>{{ $order->currency }} {{ $order->grand_total }}</span></div>
<div class="record"><strong>Delivery term</strong><span>{{ $order->incoterm?:'Not recorded' }}</span></div>
</section>
<section class="section-card"><h2>Products ordered</h2>
<div class="table-wrap"><table class="data-table">
<thead><tr><th>Product</th><th>Quantity</th><th>Unit price</th><th>Line total</th></tr></thead><tbody>
@foreach($order->lines as $line)
<tr><td>{{ $line->product_name }}</td>
<td>{{ $line->quantity }} {{ $line->unit }}</td>
<td>{{ $order->currency }} {{ $line->unit_price }}</td>
<td>{{ $order->currency }} {{ $line->line_total }}</td></tr>
@endforeach
</tbody></table></div></section>
<section class="section-card"><h2>Shipment updates</h2>
<p class="muted">Events shown below have been entered by the exporter. They are not official airline or customs confirmations.</p>
@forelse($order->shipments->sortByDesc('created_at') as $shipment)
<div class="record"><div><strong>{{ $shipment->reference }} · {{ ucfirst($shipment->status) }}</strong>
<small>{{ $shipment->origin_country }} → {{ $shipment->destination_country }}
@if($shipment->flight_number) · {{ $shipment->flight_number }}@endif
@if($shipment->air_waybill) · AWB {{ $shipment->air_waybill }}@endif</small></div></div>
@empty<p class="empty-msg">No shipment has been linked to this order yet.</p>@endforelse
</section>
<p class="form-hint">This private link expires or can be revoked by your exporter. Contact the business using a known verified channel for corrections, payments or updated documents.</p>
@endsection
