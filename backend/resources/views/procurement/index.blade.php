@extends('layouts.console')
@section('title', 'Supply Requests')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SUPPLIER SOURCING</span><h1>Supply requests</h1><p>Ask registered suppliers for prices and compare their offers.</p></div></div>
@if($canCreate)<a class="primary-button" data-remote-dialog href="{{ route('supply-requests.create') }}">New supply request</a>@endif
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('suppliers.index') }}">Suppliers</a><a class="secondary-button" href="{{ route('purchases.index') }}">Purchases</a></div>
<section class="section-card">
<h2>Requests and responses</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Reference</th><th>Products</th><th>Needed by</th><th>Currency</th><th>Offers</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($requests as $item)
<tr><td><strong>{{ $item->reference }}</strong></td><td>{{ $item->items->take(2)->pluck('product.name')->join(', ') }}@if($item->items->count()>2)<small>+ {{ $item->items->count()-2 }} more</small>@endif</td><td>{{ $item->needed_on?->format('d M Y') ?: '—' }}</td><td>{{ $item->currency }}</td><td>{{ $item->quotes_count }}</td><td><span class="tiny-pill">{{ ucfirst($item->status) }}</span></td><td><a class="action-link" href="{{ route('supply-requests.show',$item->id) }}">Open</a></td></tr>
@empty<tr><td colspan="7" class="empty-msg">No supply requests yet.</td></tr>@endforelse
</tbody></table></div>{{ $requests->links() }}
</section>
@endsection