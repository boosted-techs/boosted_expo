@extends('layouts.console')
@section('title', 'Goods Receiving')
@section('content')
<div class="page-intro"><div><span class="eyebrow">WAREHOUSE</span><h1>Goods received</h1><p>Review goods received, rejected quantities, and traceable batches.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('purchases.index') }}">Purchases awaiting receipt</a><a class="secondary-button" href="{{ route('stock.index') }}">View stock batches</a></div>
<section class="section-card"><h2>Receiving register</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Receipt</th><th>Purchase</th><th>Product</th><th>Accepted</th><th>Rejected</th><th>Batch</th></tr></thead><tbody>
@forelse($receipts as $r)<tr><td><strong>{{ $r->reference }}</strong><small>{{ $r->received_on?->format('d M Y') }}</small></td><td><a class="action-link" href="{{ route('purchases.show',$r->purchase_order_id) }}">{{ $r->purchaseOrder->reference }}</a></td><td>{{ $r->purchaseOrder->product->name }}</td><td>{{ $r->accepted_quantity }}</td><td>{{ $r->rejected_quantity }}</td><td>{{ $r->batch?->batch_code ?: '—' }}</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No goods have been received yet.</td></tr>@endforelse
</tbody></table></div>{{ $receipts->links() }}</section>
@endsection