@extends('layouts.console')
@section('title','Add Destination')
@section('content')
<div class="page-intro"><div><h1>Add destination</h1></div></div>
<section class="section-card form-narrow"><form method="POST" action="{{ route('destinations.store') }}" class="form-stack">@csrf
<label class="field"><span>Destination name</span><input name="name" required maxlength="160" placeholder="e.g. Dubai cargo terminal" value="{{ old('name') }}"></label>
<label class="field"><span>Country (optional)</span><select name="country_code"><option value="">Choose country</option>@foreach(config('countries') as $code=>$name)<option value="{{ $code }}" @selected(old('country_code')==$code)>{{ $name }} · {{ $code }}</option>@endforeach</select></label>
<label class="field"><span>City (optional)</span><input name="city" maxlength="100" value="{{ old('city') }}"></label><label class="field"><span>Location (optional)</span><input name="location" maxlength="500" value="{{ old('location') }}"></label><button class="primary-button">Save destination</button></form></section>
@endsection