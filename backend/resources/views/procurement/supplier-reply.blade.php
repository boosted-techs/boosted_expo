@extends('layouts.public')
@section('title', 'Respond to Supply Request')
@section('content')
<span class="eyebrow">SUPPLIER QUOTATION</span><h1>Supply quotation request</h1>
<p class="subhead">{{ $link->supplyRequest->reference }} · {{ $link->supplier->name }}</p>
<section class="section-card">
<strong>{{ $rfq->product->name }}</strong>
<div class="record"><span>Requested quantity</span><strong>{{ $rfq->quantity }} {{ $rfq->product->base_unit }}</strong></div>
<div class="record"><span>Currency</span><strong>{{ $rfq->currency }}</strong></div>
<div class="record"><span>Response deadline</span><strong>{{ $rfq->response_deadline->format('d M Y H:i') }}</strong></div>
@if($rfq->notes)<p>{{ $rfq->notes }}</p>@endif
</section>
@if($offer)
<div class="notice"><span>●</span><div><strong>Your quotation was received (revision {{ $offer->revision }})</strong><p>You may adjust your quantities, price or delivery date until this request closes.</p></div></div>
@endif
<form method="POST" action="{{ route('supplier-offer.store',$token) }}" class="form-stack">@csrf
<label class="field"><span>Quantity you can supply ({{ $rfq->product->base_unit }})</span><input type="number" name="quantity" min="0.001" max="{{ $rfq->quantity }}" step="0.001" value="{{ old('quantity',$offer?->quantity) }}" required></label>
<label class="field"><span>Your unit price ({{ $rfq->currency }})</span><input type="number" name="unit_price" min="0.0001" step="0.0001" value="{{ old('unit_price',$offer?->unit_price) }}" required></label>
<label class="field"><span>Proposed delivery date</span><input type="date" name="delivery_on" value="{{ old('delivery_on',$offer?->delivery_on?->format('Y-m-d')) }}"></label>
<label class="field"><span>Message or conditions (optional)</span><textarea name="notes" rows="3" maxlength="2000">{{ old('notes',$offer?->notes) }}</textarea></label>
<button class="primary-button">Submit quotation</button>
</form>
<p class="form-hint">This private link belongs to the invited supplier. No account is needed. Please do not share the link with others.</p>
@endsection