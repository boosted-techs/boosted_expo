@extends('layouts.console')
@section('title', 'Suppliers')
@section('content')
<div class="page-intro"><div><span class="eyebrow">PURCHASING</span><h1>Suppliers</h1><p>Manage produce suppliers, including regular farms and market vendors.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('supply-requests.index') }}">Supply requests</a><a class="secondary-button" href="{{ route('purchases.index') }}">Purchases</a></div>
<section class="section-card"><h2>Supplier directory</h2><a href="{{ route('partners.receiving') }}">Who supplied what? Search by business date</a><div class="table-wrap"><table class="data-table"><thead><tr><th>Supplier</th><th>Type</th><th>Contact</th><th>Area</th><th>Status</th>@if($canManage)<th></th>@endif</tr></thead><tbody>
@forelse($suppliers as $s)<tr><td><strong><a href="{{ route('partners.show',['supplier',$s]) }}">{{ $s->name }}</a></strong><small>{{ $s->supplier_code }}</small></td><td>{{ ucfirst($s->kind) }}</td><td>{{ $s->email ?: ($s->phone ?: '—') }}</td><td>{{ $s->market_location ?: '—' }}</td><td>{{ $s->active?'Active':'Inactive' }}</td>@if($canManage)<td><a class="action-link" href="{{ route('suppliers.edit',$s->id) }}">Edit</a><x-archive-action type="supplier" :record="$s" /></td>@endif</tr>
@empty<tr><td colspan="6" class="empty-msg">No suppliers yet.</td></tr>@endforelse
</tbody></table></div>{{ $suppliers->links() }}</section>
@if($canManage)
<section class="section-card form-narrow"><h2>Add supplier</h2>
<form method="POST" action="{{ route('suppliers.store') }}" class="form-stack">@csrf
<div class="form-columns"><label class="field"><span>Supplier name</span><input name="name" value="{{ old('name') }}" required maxlength="160"></label><label class="field"><span>Supplier code</span><input name="supplier_code" value="{{ old('supplier_code') }}" placeholder="FARM-001" required maxlength="50"></label></div>
<label class="field"><span>Supplier type</span><select name="kind"><option value="company">Company / business</option><option value="individual">Individual / farmer</option></select></label>
<label class="field"><span>Market or location (optional)</span><input name="market_location" value="{{ old('market_location') }}" maxlength="150"></label>
<div class="form-columns"><label class="field"><span>Email for quotation links</span><input type="email" name="email" value="{{ old('email') }}"></label><label class="field"><span>Phone</span><input name="phone" value="{{ old('phone') }}" maxlength="50"></label></div>
<label class="field"><span>Notes</span><textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea></label>
<button class="primary-button">Add supplier</button></form></section>
@endif
@endsection