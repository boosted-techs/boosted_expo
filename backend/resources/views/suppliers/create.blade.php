@extends('layouts.console')
@section('title','Add Supplier')
@section('content')
<div class="page-intro"><h1>Add supplier</h1></div>
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