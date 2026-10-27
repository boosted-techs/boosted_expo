@extends('layouts.console')
@section('title','Customer Request')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CUSTOMER ENQUIRY</span><h1>{{ $record->reference }}</h1><p>{{ $record->customer->name }} · {{ ucfirst($record->status) }}</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('sales-requests.index') }}">All requests</a><a class="secondary-button" href="{{ route('sales-orders.index') }}">Orders</a></div>
<section class="section-card"><h2>Requested produce</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Product code</th><th>Quantity</th></tr></thead><tbody>
@foreach($record->items as $item)<tr><td>{{ $item->product->name }}</td><td>{{ $item->product->sku }}</td><td>{{ $item->quantity }} {{ $item->product->base_unit }}</td></tr>@endforeach
</tbody></table></div>
<div class="record"><strong>Required by</strong><span>{{ $record->required_on?->format('d M Y') ?: 'Not specified' }}</span></div>
@if($record->notes)<p class="form-hint">{{ $record->notes }}</p>@endif
</section>
@if($canManage&&!$record->order)<section class="section-card"><h2>Approve quantity-only work</h2><p>Authorize sourcing before pricing is agreed. This creates no sale, invoice or payment.</p><form method="POST" action="{{ route('sales-requests.approve',$record) }}" class="form-stack">@csrf<input name="reason" required minlength="10" maxlength="1000" placeholder="Operational approval reason"><button class="primary-button">Approve operational order</button></form></section>@endif
<section class="section-card"><h2>Quotation history</h2>
@forelse($record->quotations->sortByDesc('revision') as $q)
<div class="record"><div><strong>{{ $q->reference }}</strong><small>{{ $q->currency }} {{ $q->grand_total }} · Revision {{ $q->revision }} · {{ ucfirst($q->status) }}</small></div>
<a class="action-link" href="{{ route('sales-quotes.show',$q->id) }}">View quotation</a></div>
@empty<p class="empty-msg">No quotations prepared yet.</p>@endforelse
@if($record->order)
<p class="notice-message">Customer order confirmed: <a href="{{ route('sales-orders.show',$record->order->id) }}" class="action-link">{{ $record->order->reference }}</a></p>
@endif
@if($canManage&&(!$record->order||$record->order->pricing_status==='unpriced'))
<form method="POST" action="{{ route('sales-requests.draft',$record->id) }}" class="form-actions" style="margin-top:15px">@csrf
<button class="primary-button">Create / open quotation draft</button></form>
@endif
</section>
@endsection