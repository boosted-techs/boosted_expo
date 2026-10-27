@extends('layouts.console')
@section('title', 'New Purchase')
@section('content')
<div class="page-intro"><div><span class="eyebrow">PURCHASING</span><h1>Record purchase</h1><p>Use this for market buying, a walk-in vendor or direct ordering from a registered supplier.</p></div></div>
<section class="section-card form-narrow">
<form action="{{ route('purchases.store') }}" method="POST" class="form-stack">@csrf
<input type="hidden" name="idempotency_key" value="{{ $formKey }}">
<label class="field"><span>Purchase source</span><select name="kind" id="purchase-kind" required><option value="market" @selected(old('kind')==='market')>Local market</option><option value="walk_in" @selected(old('kind')==='walk_in')>Walk-in supplier</option><option value="supplier" @selected(old('kind')==='supplier')>Registered supplier</option></select></label>
<div class="form-columns">
<label class="field"><span>Registered supplier (if selected)</span><select name="supplier_id"><option value="">Choose supplier</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id')==$s->id)>{{ $s->name }}</option>@endforeach</select></label>
<label class="field"><span>Vendor name (optional)</span><input name="vendor_name" value="{{ old('vendor_name') }}" maxlength="160" placeholder="Market stall or walk-in supplier"></label>
</div>
<div class="form-columns"><label class="field"><span>Vendor phone (optional)</span><input name="vendor_phone" value="{{ old('vendor_phone') }}" maxlength="50"></label>
<label class="field"><span>Market / location</span><input name="market_location" value="{{ old('market_location') }}" maxlength="150" placeholder="e.g. Nakasero"></label></div>
<label class="field"><span>Product</span><select name="product_id" required><option value="">Choose product</option>@foreach($products as $p)<option value="{{ $p->id }}" @selected(old('product_id')==$p->id)>{{ $p->name }} ({{ $p->base_unit }})</option>@endforeach</select></label>
<div class="form-columns"><label class="field"><span>Quantity</span><input name="quantity" type="number" step="0.001" min="0.001" value="{{ old('quantity') }}" required></label><label class="field"><span>Price per unit</span><input name="unit_price" type="number" step="0.0001" min="0.0001" value="{{ old('unit_price') }}" required></label></div>
<div class="form-columns"><label class="field"><span>Currency</span><x-currency-select :value="old('currency', $business->base_currency)" /></label><label class="field"><span>Purchase date</span><input name="purchased_on" type="date" value="{{ old('purchased_on',now($business->timezone)->format('Y-m-d')) }}" required></label></div>
<label class="field"><span>Notes</span><textarea name="notes" rows="3" maxlength="2000">{{ old('notes') }}</textarea></label>
<div class="notice"><span>●</span><div><strong>Purchasing and receiving are separate steps</strong><p>Goods will appear in warehouse stock only after staff confirm the received and accepted quantities.</p></div></div>
<div class="form-actions"><button class="primary-button">Save purchase</button><a class="secondary-button" href="{{ route('purchases.index') }}">Cancel</a></div></form>
</section>
@endsection