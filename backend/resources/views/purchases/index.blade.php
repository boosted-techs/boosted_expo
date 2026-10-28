@extends('layouts.console')
@section('title', 'Purchases')
@section('content')
<div class="page-intro"><div><span class="eyebrow">LOCAL SOURCING</span><h1>{{ $archived?'Archived purchases':'Purchases' }}</h1><p>Market purchases, walk-in suppliers and registered supplier orders in one place.</p></div></div>
<div class="form-actions" style="margin-bottom:18px">@if($canCreate)<a href="{{ route('purchases.create') }}" class="primary-button">Record purchase</a>@endif<a href="{{ route('supply-requests.index') }}" class="secondary-button">Supply requests</a><a href="{{ route('receiving.index') }}" class="secondary-button">Goods received</a></div>
<section class="section-card"><h2>{{ $archived?'Archived purchase register':'Purchase register' }}</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Reference</th><th>Source</th><th>Product</th><th>Quantity</th><th>Estimated value</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($purchases as $order)
<tr><td><strong>{{ $order->reference }}</strong><small>{{ $order->purchased_on?->format('d M Y') }}</small></td><td>{{ $order->supplier?->name ?: ($order->vendor_name ?: ucfirst(str_replace('_',' ',$order->kind))) }}</td><td>{{ $order->product->name }}</td><td>{{ $order->quantity }} {{ $order->product->base_unit }}</td><td>{{ $order->currency }} {{ $order->estimated_total }}</td><td><span class="tiny-pill">{{ ucfirst(str_replace('_',' ',$order->status)) }}</span></td><td>@if($archived && $canCreate)<form method="POST" action="{{ route('purchases.restore',$order->id) }}">@csrf<button class="secondary-button">Restore</button></form>@elseif(!$archived)<a class="action-link" href="{{ route('purchases.show',$order->id) }}">View</a>@endif</td></tr>
@empty<tr><td colspan="7" class="empty-msg">No purchases recorded yet.</td></tr>@endforelse
</tbody></table></div>{{ $purchases->links() }}</section>
<p class="form-hint">Purchase values are estimates for sourcing control. These records have not yet been posted as accounting entries or payments.</p>
@endsection