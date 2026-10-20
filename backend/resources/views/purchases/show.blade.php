@extends('layouts.console')
@section('title', 'Purchase Details')
@section('content')
<div class="page-intro"><div><span class="eyebrow">PURCHASE RECORD</span><h1>{{ $purchase->reference }}</h1><p>{{ $purchase->product->name }} · {{ ucfirst(str_replace('_',' ',$purchase->kind)) }}</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('purchases.index') }}">All purchases</a>@if($canReceive && $remaining->isGreaterThan(0) && in_array($purchase->status,['ordered','part_received']))<a class="primary-button" href="{{ route('receiving.create',$purchase->id) }}">Receive goods</a>@endif</div>
<section class="section-card">
<h2>Purchase summary</h2>
<div class="record"><strong>Supplier or vendor</strong><span>{{ $purchase->supplier?->name ?: ($purchase->vendor_name ?: 'Not identified') }}</span></div>
<div class="record"><strong>Location</strong><span>{{ $purchase->market_location ?: '—' }}</span></div>
<div class="record"><strong>Quantity purchased</strong><span>{{ $purchase->quantity }} {{ $purchase->product->base_unit }}</span></div>
<div class="record"><strong>Unit price</strong><span>{{ $purchase->currency }} {{ $purchase->unit_price }}</span></div>
<div class="record"><strong>Estimated total</strong><span>{{ $purchase->currency }} {{ $purchase->estimated_total }}</span></div>
<div class="record"><strong>Still awaiting receipt</strong><span>{{ $remaining }} {{ $purchase->product->base_unit }}</span></div>
<div class="record"><strong>Status</strong><span class="tiny-pill">{{ ucfirst(str_replace('_',' ',$purchase->status)) }}</span></div>
@if($purchase->notes)<p style="margin-top:15px">{{ $purchase->notes }}</p>@endif
</section>
<section class="section-card">
<h2>Goods receipts</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Receipt</th><th>Received</th><th>Accepted to stock</th><th>Rejected</th><th>Batch</th></tr></thead><tbody>
@forelse($purchase->receipts as $r)
<tr><td><strong>{{ $r->reference }}</strong><small>{{ $r->received_on?->format('d M Y') }}</small></td><td>{{ $r->received_quantity }}</td><td>{{ $r->accepted_quantity }}</td><td>{{ $r->rejected_quantity }}</td><td>{{ $r->batch?->batch_code ?: 'No accepted stock' }}</td></tr>
@empty<tr><td colspan="5" class="empty-msg">Nothing received yet.</td></tr>@endforelse
</tbody></table></div>
</section>
<p class="form-hint">Purchasing records and receiving records are not yet posted to the general ledger. Payments will be handled in the finance implementation.</p>
@endsection