@extends('layouts.console')
@section('title','Confirmed Sales Orders')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CUSTOMER SALES</span><h1>Confirmed orders</h1><p>Track confirmed buyer quantities through packing, shipments and dispatch.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a href="{{ route('sales-requests.index') }}" class="secondary-button">Customer requests</a><a href="{{ route('shipments.index') }}" class="secondary-button">Shipments</a><a href="{{ route('commercial-invoices.index') }}" class="secondary-button">Commercial invoices</a></div>
<section class="section-card"><h2>Orders</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Order</th><th>Customer</th><th>Amount</th><th>Linked shipments</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($orders as $order)<tr><td><strong>{{ $order->reference }}</strong><small>{{ $order->confirmed_at?->format('d M Y') }}</small></td><td>{{ $order->customer->name }}</td><td>{{ $order->pricing_status==='unpriced'?'Pricing pending':$order->currency.' '.$order->grand_total }}</td><td>{{ $order->shipments_count }}</td><td><span class="tiny-pill">{{ ucfirst($order->status) }}</span></td><td><a href="{{ route('sales-orders.show',$order->id) }}" class="action-link">View fulfilment</a></td></tr>
@empty<tr><td colspan="6" class="empty-msg">No confirmed orders yet. Start with a customer request; operational approval can precede pricing.</td></tr>@endforelse
</tbody></table></div>{{ $orders->links() }}
</section>
<p class="form-hint">Order amounts are confirmed commercial terms. Shipments may fulfil an order partially. No payments or accounting entries are generated automatically.</p>
@endsection