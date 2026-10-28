@extends('layouts.console')
@section('title','Customer Requests')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CUSTOMER SALES</span><h1>Customer requests</h1><p>Record enquiries and prepare versioned price quotations for buyers.</p></div></div>
<div class="form-actions" style="margin-bottom:18px">
@if($canManage)<a href="{{ route('sales-requests.create') }}" class="primary-button">New customer request</a>@endif
<a href="{{ route('sales-orders.index') }}" class="secondary-button">Confirmed orders</a>
<a href="{{ route('customers.index') }}" class="secondary-button">Customers</a>
</div>
<section class="section-card"><h2>Request register</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Request</th><th>Buyer</th><th>Required by</th><th>Quotes</th><th>Status</th><th></th></tr></thead>
<tbody>@forelse($requests as $record)
<tr><td><strong>{{ $record->reference }}</strong><small>{{ $record->created_at->format('d M Y') }}</small></td>
<td>{{ $record->customer->name }}</td><td>{{ $record->required_on?->format('d M Y') ?: 'Not specified' }}</td>
<td>{{ $record->quotations_count }}</td><td><span class="tiny-pill">{{ ucfirst($record->status) }}</span></td>
<td><a class="action-link" href="{{ route('sales-requests.show',$record->id) }}">Open</a></td></tr>
@empty<tr><td colspan="6" class="empty-msg">No customer requests yet.</td></tr>@endforelse
</tbody></table></div>{{ $requests->links() }}</section>
<p class="form-hint">Record buyer acceptance with a purchase order or written confirmation.</p>
@endsection