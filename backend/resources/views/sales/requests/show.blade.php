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
<div class="page-toolbar">@if($canManage&&!$record->order)<a class="secondary-button" data-remote-dialog href="{{ route('sales-requests.show',['record'=>$record->id,'activity'=>'approval']) }}">Approve quantity-only work</a>@endif<a class="secondary-button" data-remote-dialog href="{{ route('sales-requests.show',['record'=>$record->id,'activity'=>'quotes']) }}">Quotation history</a></div>
@endsection