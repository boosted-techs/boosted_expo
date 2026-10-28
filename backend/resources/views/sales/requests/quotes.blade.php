@extends('layouts.console')
@section('title','Quotation history')
@section('content')
<section class="section-card"><h2>Quotation history</h2>
@forelse($record->quotations->sortByDesc('revision') as $q)
<div class="record"><div><strong>{{ $q->reference }}</strong><small>{{ $q->currency }} {{ $q->grand_total }} · Revision {{ $q->revision }} · {{ ucfirst($q->status) }}</small></div>
<a class="action-link" href="{{ route('sales-quotes.show',$q->id) }}">View quotation</a></div>
@empty<p class="empty-msg">No quotations prepared yet.</p>@endforelse
@if($record->order)
<p class="notice-message">Customer order confirmed: <a href="{{ route('sales-orders.show',$record->order->id) }}" class="action-link">{{ $record->order->reference }}</a></p>
@endif
@if($canManage&&(!$record->order||$record->order->pricing_status==='unpriced'))
<form method="POST" action="{{ route('sales-requests.draft',$record->id) }}" class="form-actions" style="margin-top:15px">@csrf
<button class="primary-button">Create / open quotation draft</button></form>
@endif
</section>

@endsection