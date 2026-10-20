@extends('layouts.console')
@section('title', 'Receive Goods')
@section('content')
<div class="page-intro"><div><span class="eyebrow">WAREHOUSE</span><h1>Receive goods</h1><p>{{ $purchase->reference }} · {{ $purchase->product->name }} · Purchased {{ $purchase->quantity }} {{ $purchase->product->base_unit }}</p></div></div>
<section class="section-card form-narrow">
<form method="POST" action="{{ route('receiving.store',$purchase->id) }}" class="form-stack">@csrf
<input type="hidden" name="idempotency_key" value="{{ $formKey }}">
<div class="form-columns"><label class="field"><span>Accepted quantity ({{ $purchase->product->base_unit }})</span><input name="accepted_quantity" type="number" step="0.001" min="0" value="{{ old('accepted_quantity') }}" required></label><label class="field"><span>Rejected quantity ({{ $purchase->product->base_unit }})</span><input name="rejected_quantity" type="number" step="0.001" min="0" value="{{ old('rejected_quantity','0') }}" required></label></div>
<p class="form-hint">Accepted plus rejected cannot exceed the unreceived quantity. Rejected goods do not enter usable stock.</p>
<div class="form-columns"><label class="field"><span>Gross weight (kg, optional)</span><input name="gross_weight_kg" type="number" step="0.001" min="0" value="{{ old('gross_weight_kg') }}"></label><label class="field"><span>Packaging tare weight (kg)</span><input name="tare_weight_kg" type="number" step="0.001" min="0" value="{{ old('tare_weight_kg') }}"></label></div>
<label class="field"><span>Farmer / origin code (optional)</span><input name="farmer_code" value="{{ old('farmer_code') }}" maxlength="120"></label>
<label class="field"><span>Quality and inspection notes</span><textarea name="quality_notes" rows="3" maxlength="2000">{{ old('quality_notes') }}</textarea></label>
<label class="field"><span>Receipt date</span><input name="received_on" type="date" value="{{ old('received_on',now()->format('Y-m-d')) }}" required></label>
<div class="notice"><span>●</span><div><strong>Posting goods updates warehouse stock</strong><p>Accepted goods receive a new traceable batch. Confirm the quantities before posting.</p></div></div>
<div class="form-actions"><button class="primary-button">Confirm goods received</button><a class="secondary-button" href="{{ route('purchases.show',$purchase->id) }}">Cancel</a></div>
</form></section>
@endsection