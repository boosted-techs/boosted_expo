@extends('layouts.console')
@section('title', 'Edit Supplier')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SUPPLIERS</span><h1>Edit supplier</h1><p>{{ $supplier->name }}</p></div></div>
<section class="section-card form-narrow">
<form method="POST" action="{{ route('suppliers.update',$supplier->id) }}" class="form-stack">@csrf @method('PUT')
<div class="form-columns"><label class="field"><span>Name</span><input name="name" value="{{ old('name',$supplier->name) }}" required></label><label class="field"><span>Supplier code</span><input name="supplier_code" value="{{ old('supplier_code',$supplier->supplier_code) }}" required></label></div>
<label class="field"><span>Type</span><select name="kind"><option value="company" @selected($supplier->kind==='company')>Company</option><option value="individual" @selected($supplier->kind==='individual')>Individual</option></select></label>
<label class="field"><span>Market or location</span><input name="market_location" value="{{ old('market_location',$supplier->market_location) }}"></label>
<div class="form-columns"><label class="field"><span>Email</span><input type="email" name="email" value="{{ old('email',$supplier->email) }}"></label><label class="field"><span>Phone</span><input name="phone" value="{{ old('phone',$supplier->phone) }}"></label></div>
<label class="field"><span>Notes</span><textarea name="notes" rows="2">{{ old('notes',$supplier->notes) }}</textarea></label>
<input name="active" type="hidden" value="0"><label class="check-row"><input type="checkbox" name="active" value="1" @checked(old('active',$supplier->active))> Active supplier</label>
<div class="form-actions"><button class="primary-button">Save changes</button><a class="secondary-button" href="{{ route('suppliers.index') }}">Cancel</a></div>
</form></section>
@endsection