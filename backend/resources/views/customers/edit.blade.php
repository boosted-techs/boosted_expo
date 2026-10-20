@extends('layouts.console')
@section('title', 'Edit Customer')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CUSTOMERS</span><h1>Edit customer</h1><p>{{ $customer->name }}</p></div></div>
<section class="section-card form-narrow">
<form method="POST" action="{{ route('customers.update',$customer->id) }}" class="form-stack">@csrf @method('PUT')
<div class="form-columns"><label class="field"><span>Customer name</span><input name="name" value="{{ old('name',$customer->name) }}" required maxlength="160"></label><label class="field"><span>Customer code</span><input name="customer_code" value="{{ old('customer_code',$customer->customer_code) }}" required maxlength="50"></label></div>
<div class="form-columns"><label class="field"><span>Country</span><input name="country_code" value="{{ old('country_code',$customer->country_code) }}" required minlength="2" maxlength="2"></label><label class="field"><span>Trading currency</span><input name="currency" value="{{ old('currency',$customer->currency) }}" required minlength="3" maxlength="3"></label></div>
<label class="field"><span>City</span><input name="city" value="{{ old('city',$customer->city) }}"></label>
<div class="form-columns"><label class="field"><span>Email</span><input type="email" name="email" value="{{ old('email',$customer->email) }}"></label><label class="field"><span>Phone</span><input name="phone" value="{{ old('phone',$customer->phone) }}"></label></div>
<label class="field"><span>Notes</span><textarea name="notes" rows="3">{{ old('notes',$customer->notes) }}</textarea></label>
<input name="active" type="hidden" value="0"><label class="check-row"><input type="checkbox" name="active" value="1" @checked(old('active',$customer->active))> Active customer</label>
<div class="form-actions"><button class="primary-button">Save changes</button><a class="secondary-button" href="{{ route('customers.index') }}">Cancel</a></div>
</form></section>
@endsection