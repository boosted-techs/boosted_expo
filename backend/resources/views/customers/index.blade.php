@extends('layouts.console')
@section('title','Customers')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SALES</span><h1>Customers</h1><p>Customer contacts, statements and orders.</p></div>@if($canManage)<a href="{{ route('customers.create') }}" data-remote-dialog class="primary-button">Add customer</a>@endif</div>
<div data-refresh-notice hidden class="flash success no-print" role="status">Changes saved. <button type="button" class="secondary-button" data-refresh-directory>Refresh directory</button></div>
<section class="section-card" data-directory id="customer-directory"><h2>Customer directory</h2><div class="table-wrap"><table class="data-table"><thead><tr><th data-sort="name">Customer</th><th data-sort="country_code">Country</th><th>Currency</th><th data-sort="email">Contact</th><th data-sort="active">Status</th><th>Actions</th></tr></thead><tbody>
@forelse($customers as $c)<tr><td><strong><a href="{{ route('catalogue.statement',['customer',$c->id]) }}" data-remote-dialog>{{ $c->name }}</a></strong><small>{{ $c->business_name ?: $c->customer_code }}</small></td><td>{{ $c->country_code }} {{ $c->city }}</td><td>{{ $c->currency }}</td><td>{{ $c->email ?: ($c->phone ?: '—') }}</td><td><span class="status-badge {{ $c->active?'':'hold' }}">{{ $c->active?'Active':'Archived' }}</span></td><td><x-row-actions>
<a href="{{ route('catalogue.statement',['customer',$c->id]) }}" data-remote-dialog>Customer statement</a>
@if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'sales.orders.view'))<a href="{{ route('partners.show',['customer',$c->id,'activity'=>'orders']) }}" data-remote-dialog>Customer orders</a>@endif
<a href="{{ route('partners.show',['customer',$c->id,'activity'=>'history']) }}" data-remote-dialog>Customer history</a>
@if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'finance.view'))<a href="{{ route('partners.show',['customer',$c->id,'activity'=>'documents']) }}" data-remote-dialog>Financial source documents</a>@endif
<a href="{{ route('partners.show',['customer',$c->id,'activity'=>'followups']) }}" data-remote-dialog>Follow-ups & responsibilities</a>
@if($canManage)<a href="{{ route('customers.invitations',$c->id) }}" data-remote-dialog>Ordering invitations</a><a href="{{ route('customers.edit',$c->id) }}" data-remote-dialog>Edit customer</a><x-archive-action type="customer" :record="$c" />@endif
</x-row-actions></td></tr>@empty<tr><td colspan="6">No matching customers.</td></tr>@endforelse
</tbody></table></div>{{ $customers->links() }}</section>
@endsection
