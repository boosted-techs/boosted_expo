@extends('layouts.console')
@section('title', 'Customers')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SALES</span><h1>Customers</h1><p>Keep your known international buyers and their trading details in one place.</p></div></div>
<section class="section-card">
<h2>Customer directory</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Customer</th><th>Country</th><th>Currency</th><th>Contact</th><th>Status</th>@if($canManage)<th></th>@endif</tr></thead><tbody>
@forelse($customers as $c)
<tr><td><strong><a href="{{ route('partners.show',['customer',$c]) }}">{{ $c->name }}</a></strong><small>{{ $c->customer_code }}</small></td><td>{{ $c->country_code }} {{ $c->city }}</td><td>{{ $c->currency }}</td><td>{{ $c->email ?: ($c->phone ?: '—') }}</td><td>{{ $c->active?'Active':'Inactive' }}</td>@if($canManage)<td><a class="action-link" href="{{ route('customers.edit',$c->id) }}">Edit</a></td>@endif</tr>
@empty<tr><td colspan="6" class="empty-msg">No customers yet.</td></tr>@endforelse
</tbody></table></div>{{ $customers->links() }}
</section>
@if($canManage)
<section class="section-card"><h2>Customer ordering invitations</h2><p>Send a private, expiring link to a registered customer. Choose the products they may request; customer identities do not consume internal staff seats.</p>@foreach($customers as $c)<details class="sticker-panel" style="margin-bottom:12px"><summary>{{ $c->name }} · {{ $c->email??'Email needed' }}</summary><form method="POST" action="{{ route('customer-ordering.invite',$c) }}" class="form-stack">@csrf<label class="field"><span>Products this buyer can request</span><select name="product_ids[]" multiple required size="5">@foreach($orderingProducts as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->base_unit }})</option>@endforeach</select></label><button class="secondary-button">Email private ordering invitation</button></form>@foreach($orderingLinks->where('customer_id',$c->id) as $link)<p>{{ $link->delivery_status }} · {{ $link->revoked_at?'Revoked':'Expires '.$link->expires_at->format('d M Y') }}</p>@if(!$link->revoked_at)<form method="POST" action="{{ route('customer-ordering.revoke',$link) }}">@csrf<button class="secondary-button">Revoke access</button></form>@endif @endforeach</details>@endforeach</section>
<section class="section-card form-narrow"><h2>Add customer</h2><form action="{{ route('customers.store') }}" method="POST" class="form-stack">@csrf
<div class="form-columns"><label class="field"><span>Customer name</span><input name="name" value="{{ old('name') }}" required maxlength="160"></label><label class="field"><span>Customer code</span><input name="customer_code" value="{{ old('customer_code') }}" placeholder="BUYER-001" required maxlength="50"></label></div>
<div class="form-columns"><label class="field"><span>Country (2 letters)</span><input name="country_code" value="{{ old('country_code','AE') }}" minlength="2" maxlength="2" required></label><label class="field"><span>Trading currency</span><x-currency-select :value="old('currency','USD')" /></label></div>
<label class="field"><span>City (optional)</span><input name="city" value="{{ old('city') }}" maxlength="100"></label>
<div class="form-columns"><label class="field"><span>Email</span><input type="email" name="email" value="{{ old('email') }}"></label><label class="field"><span>Phone</span><input name="phone" value="{{ old('phone') }}" maxlength="50"></label></div>
<label class="field"><span>Notes</span><textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea></label>
<button class="primary-button">Add customer</button></form></section>
@endif
@endsection