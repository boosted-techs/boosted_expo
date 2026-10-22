@extends('layouts.console')
@section('title','New Customer Request')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SALES ENQUIRY</span><h1>New customer request</h1><p>Record what the buyer wants before preparing a priced quotation.</p></div></div>
<section class="section-card form-narrow">
<form method="POST" action="{{ route('sales-requests.store') }}" class="form-stack">@csrf
<label class="field"><span>Buyer / customer</span><select name="customer_id" required><option value="">Choose customer</option>
@foreach($customers as $buyer)<option value="{{ $buyer->id }}" @selected(old('customer_id')==$buyer->id)>{{ $buyer->name }} · {{ $buyer->country_code }}</option>@endforeach
</select></label>
<label class="field"><span>Requested delivery date (optional)</span><input type="date" name="required_on" value="{{ old('required_on') }}"></label>
<h2 style="margin:0">Products requested</h2>
<p class="muted">Add up to 20 different products. All quantities use each product's inventory unit.</p>
<div id="request-products">
<div class="form-columns product-request-line">
<label class="field"><span>Product</span><select name="items[0][product_id]" required><option value="">Choose product</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->base_unit }})</option>@endforeach</select></label>
<label class="field"><span>Requested quantity</span><input name="items[0][quantity]" type="number" min="0.001" step="0.001" required></label></div>
</div>
<button type="button" id="add-request-product" class="secondary-button">Add another product</button>
<template id="request-line-template"><div class="form-columns product-request-line">
<label class="field"><span>Product</span><select name="items[__I__][product_id]" required><option value="">Choose product</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->base_unit }})</option>@endforeach</select></label>
<label class="field"><span>Quantity</span><input name="items[__I__][quantity]" type="number" step="0.001" min="0.001" required></label>
<button type="button" class="text-button remove-product" style="justify-self:start">Remove</button></div></template>
<label class="field"><span>Customer requirements / notes</span><textarea name="notes" rows="3" maxlength="2000">{{ old('notes') }}</textarea></label>
<div class="form-actions"><button class="primary-button" @disabled($customers->isEmpty() || $products->isEmpty())>Record request</button><a href="{{ route('sales-requests.index') }}" class="secondary-button">Cancel</a></div>
</form>
@if($customers->isEmpty() || $products->isEmpty())<p class="notice-message">Add an active customer and product before recording a request.</p>@endif
</section>
<script src="/assets/sales-request-ui.js" defer></script>
@endsection