@extends('layouts.console')
@section('title','Sales Order Fulfilment')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CONFIRMED CUSTOMER ORDER</span><h1>{{ $order->reference }}</h1><p>{{ $order->customer->name }} · {{ $order->currency }} {{ $order->grand_total }}</p></div><span class="tiny-pill">CONFIRMED</span></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('sales-orders.index') }}">All orders</a>
<a class="secondary-button" href="{{ route('sales-quotes.show',$order->sales_quotation_id) }}">Accepted quotation</a>
@if($canLink)<a class="primary-button" href="{{ route('shipments.create',['customer_id'=>$order->customer_id,'sales_order_id'=>$order->id]) }}">Plan shipment for order</a>@endif</div>
<section class="section-card"><h2>Confirmed commercial terms</h2>
<div class="record"><strong>Buyer confirmation</strong><span>{{ $order->buyer_confirmation }}</span></div>
<div class="record"><strong>Currency</strong><span>{{ $order->currency }}</span></div>
<div class="record"><strong>Delivery term</strong><span>{{ $order->incoterm ?: 'Not agreed' }}</span></div>
<div class="record"><strong>Subtotal</strong><span>{{ $order->currency }} {{ $order->subtotal }}</span></div>
<div class="record"><strong>Discount / tax</strong><span>{{ $order->discount_percent }}% / {{ $order->tax_percent }}%</span></div>
<div class="record"><strong>Confirmed order total</strong><span>{{ $order->currency }} {{ $order->grand_total }}</span></div>
<p class="form-hint">Terms were copied from the exact customer quotation accepted. An order is not proof of payment.</p>
</section>
<section class="section-card"><h2>Quantity fulfilment</h2>
<p class="muted">An order can be fulfilled through several shipments. Quantities are allocated from packed cartons, so the system does not deduct source stock twice.</p>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Ordered</th><th>Allocated</th><th>Still to allocate</th><th>Dispatched</th><th>Delivered (manual)</th></tr></thead><tbody>
@foreach($summary['lines'] as $line)
<tr><td><strong>{{ $line['product_name']??$line['product']->name }}</strong><small>{{ $line['product']->sku }}</small></td>
<td>{{ $line['ordered'] }} {{ $line['product']->base_unit }}</td>
<td>{{ $line['allocated'] }} {{ $line['product']->base_unit }}</td>
<td><strong>{{ $line['remaining'] }} {{ $line['product']->base_unit }}</strong></td>
<td>{{ $line['dispatched'] }} {{ $line['product']->base_unit }}</td>
<td>{{ $line['delivered'] }} {{ $line['product']->base_unit }}</td>
</tr>@endforeach</tbody></table></div></section>
<section class="section-card"><h2>Shipments linked to this order</h2>
@forelse($summary['shipments'] as $shipment)
<div class="record"><div><strong>{{ $shipment->reference }}</strong><small>{{ ucfirst($shipment->status) }} · {{ $shipment->allocations->count() }} carton(s) · {{ $shipment->flight_number ?: 'Flight pending' }}</small></div><a class="action-link" href="{{ route('shipments.show',$shipment->id) }}">Open shipment</a></div>
@empty<p class="empty-msg">No shipments linked yet.</p>@endforelse
</section>
@if($canLink && $draftShipments->isNotEmpty())
<section class="section-card"><h2>Link an existing shipment</h2>
<p class="muted">Only draft shipments for this buyer are eligible. Existing cartons will be checked against remaining quantities before linking.</p>
@foreach($draftShipments as $shipment)
<div class="record"><div><strong>{{ $shipment->reference }}</strong><small>{{ $shipment->origin_country }} → {{ $shipment->destination_country }}</small></div>
<form method="POST" action="{{ route('sales-orders.shipments.link',['order'=>$order->id,'shipment'=>$shipment->id]) }}">@csrf
<button class="secondary-button">Link to order</button></form></div>
@endforeach
</section>
@endif
<section class="section-card"><h2>Commercial invoices</h2>
@forelse($order->invoices as $invoice)
<div class="record"><div><strong>{{ $invoice->reference }}</strong><small>{{ $invoice->currency }} {{ $invoice->grand_total }} · {{ $invoice->shipment?->reference }}</small></div><a href="{{ route('commercial-invoices.show',$invoice->id) }}" class="action-link">Open invoice</a></div>
@empty<p class="empty-msg">No commercial invoices issued yet. Issue an invoice against an eligible shipment, not against unshipped order quantities.</p>@endforelse
</section>
@if($canShare)
<section class="section-card"><h2>Private buyer order tracking</h2>
<p class="muted">Email a read-only, time-limited tracking link directly to the buyer's registered email address. The link shows only this order, prices and manually recorded shipment milestones. It does not reveal internal costs or finance records.</p>
<div class="record"><strong>Registered buyer email</strong><span>{{ $order->customer->email?:'No buyer email on file' }}</span></div>
<form method="POST" action="{{ route('sales-orders.buyer-link.share',$order->id) }}" class="form-actions">@csrf
<button class="primary-button" @disabled(!filter_var($order->customer->email,FILTER_VALIDATE_EMAIL))>Email private tracking link</button></form>
@forelse($buyerLinks as $link)
<div class="record"><div><strong>{{ $link->buyer_email }}</strong>
<small>Issued {{ $link->created_at->format('d M Y') }} · Expires {{ $link->expires_at->format('d M Y') }}
@if($link->revoked_at)
· Revoked
@endif
@if($link->expires_at->isPast())
· Expired
@endif
</small></div>
@if(!$link->revoked_at && $link->expires_at->isFuture())
<form method="POST" action="{{ route('sales-orders.buyer-link.revoke',[
'order'=>$order->id,'link'=>$link->id]) }}">@csrf
<button class="secondary-button">Revoke</button></form>
@endif
</div>
@empty<p class="empty-msg">No tracking invitations have been issued.</p>@endforelse
</section>
@endif
@endsection