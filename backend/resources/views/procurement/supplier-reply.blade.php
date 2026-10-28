@extends('layouts.public')
@section('title','Supply Quotation')
@section('content')
<span class="eyebrow">SUPPLY QUOTATION</span><h1>Your offer</h1><p class="subhead">{{ $rfq->reference }} · {{ $link->supplier->name }}</p>
<div class="record"><span>Replies close</span><strong>{{ $rfq->response_deadline->format('d M Y H:i') }}</strong></div>
@if($rfq->notes)<p>{{ $rfq->notes }}</p>@endif
<form method="POST" action="{{ route('supplier-offer.store',$token) }}" class="form-stack">@csrf
<p class="form-hint">Enter quantity and unit price for products you can supply. Leave other rows blank.</p>
<div class="table-wrap"><table class="data-table" data-table-tools="off"><thead><tr><th>#</th><th>Product</th><th>Requested</th><th>Your quantity</th><th>Unit price ({{ $rfq->currency }})</th></tr></thead><tbody>
@foreach($rfq->items as $i=>$item)@php($previous=$offers->get($item->id))
<tr><td>{{ $loop->iteration }}</td><td>{{ $item->product->name }}</td><td>{{ $item->quantity }} {{ $item->product->base_unit }}</td>
@if($previous?->status==='selected')<td colspan="2">Selected · {{ $previous->quantity }} × {{ $previous->unit_price }}</td>
@elseif(!$item->product->active)<td colspan="2">Unavailable</td>
@else<td><input type="hidden" name="items[{{ $i }}][supply_request_item_id]" value="{{ $item->id }}"><input aria-label="Quantity for {{ $item->product->name }}" type="number" name="items[{{ $i }}][quantity]" min="0.001" max="{{ $item->quantity }}" step="0.001" placeholder="0.000" value="{{ old('items.'.$i.'.quantity',$previous?->quantity) }}"></td><td><input aria-label="Unit price for {{ $item->product->name }}" type="number" name="items[{{ $i }}][unit_price]" min="0.0001" step="0.0001" placeholder="0.00" value="{{ old('items.'.$i.'.unit_price',$previous?->unit_price) }}"></td>@endif</tr>
@endforeach</tbody></table></div>
<label class="field"><span>Delivery date (optional)</span><input type="date" name="delivery_on" value="{{ old('delivery_on',$offer?->delivery_on?->format('Y-m-d')) }}"></label>
<label class="field"><span>Notes (optional)</span><textarea name="notes" rows="2" maxlength="2000">{{ old('notes',$offer?->notes) }}</textarea></label><button class="primary-button">Save quotation</button></form>
@endsection