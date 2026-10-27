@extends('layouts.console')
@section('title', 'Supply Requests')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SUPPLIER SOURCING</span><h1>Supply requests</h1><p>Ask registered suppliers for prices and compare their offers.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('suppliers.index') }}">Suppliers</a><a class="secondary-button" href="{{ route('purchases.index') }}">Purchases</a></div>
<section class="section-card">
<h2>Requests and responses</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Reference</th><th>Product</th><th>Needed</th><th>Currency</th><th>Quotes</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($requests as $item)
<tr><td><strong>{{ $item->reference }}</strong></td><td>{{ $item->product->name }}</td><td>{{ $item->quantity }} {{ $item->product->base_unit }}</td><td>{{ $item->currency }}</td><td>{{ $item->quotes_count }}</td><td><span class="tiny-pill">{{ ucfirst($item->status) }}</span></td><td><a class="action-link" href="{{ route('supply-requests.show',$item->id) }}">Open</a></td></tr>
@empty<tr><td colspan="7" class="empty-msg">No supply requests yet.</td></tr>@endforelse
</tbody></table></div>{{ $requests->links() }}
</section>
@if($canCreate)
<section class="section-card form-narrow">
<h2>New supply request</h2>
<p class="muted">One product per request in this version. You can invite several suppliers to respond.</p>
<form method="POST" action="{{ route('supply-requests.store') }}" class="form-stack">@csrf
<label class="field"><span>Product</span><select name="product_id" required><option value="">Choose a product</option>@foreach($products as $p)<option value="{{ $p->id }}" @selected(old('product_id')==$p->id)>{{ $p->name }} ({{ $p->base_unit }})</option>@endforeach</select></label>
<div class="form-columns"><label class="field"><span>Required quantity</span><input name="quantity" type="number" min="0.001" step="0.001" value="{{ old('quantity') }}" required></label><label class="field"><span>Quotation currency</span><x-currency-select :value="old('currency','UGX')" /></label></div>
<div class="form-columns"><label class="field"><span>Needed by (optional)</span><input name="needed_on" type="date" value="{{ old('needed_on') }}"></label><label class="field"><span>Responses close</span><input name="response_deadline" type="datetime-local" required value="{{ old('response_deadline') }}"></label></div>
<label class="field"><span>Information for suppliers</span><textarea name="notes" rows="3" maxlength="2000">{{ old('notes') }}</textarea></label>
<button class="primary-button">Create request</button>
</form></section>
@endif
@endsection