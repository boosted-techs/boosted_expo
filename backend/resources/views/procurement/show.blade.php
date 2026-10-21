@extends('layouts.console')
@section('title', 'Supply Request')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SUPPLY REQUEST</span><h1>{{ $rfq->reference }}</h1><p>{{ $rfq->product->name }} · {{ $rfq->quantity }} {{ $rfq->product->base_unit }} · {{ $rfq->currency }}</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a href="{{ route('supply-requests.index') }}" class="secondary-button">All requests</a><a href="{{ route('purchases.index') }}" class="secondary-button">Purchases</a></div>
<section class="section-card">
<h2>Request details</h2>
<div class="record"><div><strong>Status</strong><small>{{ ucfirst($rfq->status) }}</small></div><span class="tiny-pill">{{ $rfq->status }}</span></div>
<div class="record"><div><strong>Responses close</strong><small>{{ $rfq->response_deadline->format('d M Y, H:i') }}</small></div></div>
<div class="record"><div><strong>Delivery requested</strong><small>{{ $rfq->needed_on?->format('d M Y') ?: 'Not specified' }}</small></div></div>
@if($rfq->notes)<p style="margin-top:15px">{{ $rfq->notes }}</p>@endif
@if($purchase)<p class="notice-message">Selected supplier: <a class="action-link" href="{{ route('purchases.show',$purchase->id) }}">{{ $purchase->reference }} — View purchase</a></p>@endif
</section>
@if($canCreate && $rfq->status==='draft')
<section class="section-card">
<h2>Send to suppliers</h2>
<p class="muted">Choose one or more suppliers who have email addresses. Each receives a private link to submit or revise their price without creating an account.</p>
<form action="{{ route('supply-requests.issue',$rfq->id) }}" method="POST" class="form-stack">@csrf
<div class="supplier-choices">
@forelse($suppliers as $supplier)
<label class="check-row"><input type="checkbox" name="supplier_ids[]" value="{{ $supplier->id }}" @disabled(!$supplier->email)> <span>{{ $supplier->name }} <small>· {{ $supplier->email ?: 'Add an email address first' }}</small></span></label>
@empty<p class="empty-msg">There are no active suppliers. <a href="{{ route('suppliers.index') }}" class="action-link">Add one</a>.</p>@endforelse
</div>
<button class="primary-button">Issue request</button>
</form></section>
@endif
@if($rfq->links->isNotEmpty())
<section class="section-card">
<h2>Invited suppliers</h2>
@foreach($rfq->links as $link)<div class="record"><div><strong>{{ $link->supplier->name }}</strong><small>{{ $link->supplier->email ?: 'No email recorded' }}</small></div><span class="tiny-pill">{{ $link->sent_at ? 'Invitation processed' : 'Delivery not confirmed' }}</span></div>@endforeach
</section>
@endif
<section class="section-card">
<h2>Supplier quotations</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Supplier</th><th>Offered qty</th><th>Unit price</th><th>Delivery</th><th>Revision</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($rfq->quotes as $offer)
<tr><td><strong>{{ $offer->supplier->name }}</strong></td><td>{{ $offer->quantity }} {{ $rfq->product->base_unit }}</td><td>{{ $rfq->currency }} {{ $offer->unit_price }}</td><td>{{ $offer->delivery_on?->format('d M Y') ?: '—' }}</td><td>{{ $offer->revision }}</td><td>{{ ucfirst($offer->status) }}</td><td>
@if($canAward && $rfq->status==='sent')
<form method="POST" action="{{ route('supply-requests.award',['rfq'=>$rfq->id,'quote'=>$offer->id]) }}" data-confirm="Select this supplier and create a purchase order?">@csrf<button class="secondary-button" type="submit">Select offer</button></form>
@else — @endif</td></tr>
@if($offer->revisions->count()>1)<tr><td colspan="7"><details><summary>View {{ $offer->revisions->count() }} revisions</summary>@foreach($offer->revisions->sortBy('revision') as $revision)<p class="form-hint">Revision {{ $revision->revision }}: {{ $revision->quantity }} × {{ $rfq->currency }} {{ $revision->unit_price }} · {{ $revision->submitted_via }} · {{ $revision->created_at }}</p>@endforeach</details></td></tr>@endif
@empty<tr><td colspan="7" class="empty-msg">No quotations received yet.</td></tr>@endforelse
</tbody></table></div>
<p class="form-hint">Only an authorised owner or administrator can select an offer. Confirming creates a purchase order; it does not record a payment.</p>
</section>
@if($canCreate && $rfq->status==='sent' && $rfq->response_deadline->isFuture())
<section class="section-card form-narrow"><h2>Record a supplier's quotation</h2>
<p class="muted">Use this if a supplier sends a quotation by phone or in person. Existing quotations are saved as new revisions.</p>
<form action="{{ route('supply-requests.quotes.store',$rfq->id) }}" method="POST" class="form-stack">@csrf
<label class="field"><span>Supplier</span><select name="supplier_id" required><option value="">Choose invited supplier</option>@foreach($rfq->links as $link)<option value="{{ $link->supplier_id }}">{{ $link->supplier->name }}</option>@endforeach</select></label>
<div class="form-columns"><label class="field"><span>Available quantity ({{ $rfq->product->base_unit }})</span><input type="number" name="quantity" step="0.001" min="0.001" max="{{ $rfq->quantity }}" required></label><label class="field"><span>Unit price ({{ $rfq->currency }})</span><input type="number" name="unit_price" step="0.0001" min="0.0001" required></label></div>
<label class="field"><span>Proposed delivery date</span><input type="date" name="delivery_on"></label>
<label class="field"><span>Notes</span><textarea name="notes" rows="2"></textarea></label>
<button class="primary-button">Save supplier quotation</button>
</form></section>
@endif
@endsection