@extends('layouts.console')
@section('title', 'Suppliers')
@section('content')
<div class="page-intro"><div><span class="eyebrow">PURCHASING</span><h1>Suppliers</h1><p>Manage produce suppliers, including regular farms and market vendors.</p></div>@if($canManage)<a class="primary-button" data-remote-dialog href="{{ route('suppliers.create') }}">Add supplier</a>@endif</div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('supply-requests.index') }}">Supply requests</a><a class="secondary-button" href="{{ route('purchases.index') }}">Purchases</a></div>
<section class="section-card"><h2>Supplier directory</h2><a href="{{ route('partners.receiving') }}">Who supplied what? Search by business date</a><div class="table-wrap"><table class="data-table"><thead><tr><th>Supplier</th><th>Type</th><th>Contact</th><th>Area</th><th>Status</th>@if($canManage)<th></th>@endif</tr></thead><tbody>
@forelse($suppliers as $s)<tr><td><strong><a href="{{ route('partners.show',['supplier',$s]) }}">{{ $s->name }}</a></strong><small>{{ $s->supplier_code }}</small></td><td>{{ ucfirst($s->kind) }}</td><td>{{ $s->email ?: ($s->phone ?: '—') }}</td><td>{{ $s->market_location ?: '—' }}</td><td>{{ $s->active?'Active':'Inactive' }}</td>@if($canManage)<td><a class="action-link" href="{{ route('suppliers.edit',$s->id) }}">Edit</a><x-archive-action type="supplier" :record="$s" /></td>@endif</tr>
@empty<tr><td colspan="6" class="empty-msg">No suppliers yet.</td></tr>@endforelse
</tbody></table></div>{{ $suppliers->links() }}</section>
@endsection