@extends('layouts.console')
@section('title','New Customer Request')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SALES</span><h1>New customer request</h1></div></div>
<section class="section-card"><form method="POST" action="{{ route('sales-requests.store') }}" class="form-stack">@csrf
<input type="hidden" name="submission_key" value="{{ old('submission_key',\Illuminate\Support\Str::uuid()) }}">
<div class="form-columns"><label class="field"><span>Order reference (optional)</span><input name="reference" maxlength="40" placeholder="Created automatically if blank" value="{{ old('reference') }}"></label><label class="field"><span>Buyer reference (optional)</span><input name="buyer_reference" maxlength="120" value="{{ old('buyer_reference') }}"></label></div>
<div class="form-columns"><label class="field"><span>Customer</span><select name="customer_id" required><option value="">Choose customer</option>@foreach($customers as $buyer)<option value="{{ $buyer->id }}" @selected(old('customer_id')==$buyer->id)>{{ $buyer->name }} · {{ $buyer->country_code }}</option>@endforeach</select></label>
<div class="field"><label for="request-destination">Destination (optional)</label><select id="request-destination" name="destination_id" data-destination-select><option value="">Choose destination</option>@foreach($destinations as $destination)<option value="{{ $destination->id }}" @selected(old('destination_id')==$destination->id)>{{ $destination->name }}</option>@endforeach</select><a class="action-link" data-remote-dialog data-selection-target="[data-destination-select]" href="{{ route('destinations.create') }}">+ Add destination</a></div></div>
<label class="field"><span>Required by (optional)</span><input type="date" name="required_on" value="{{ old('required_on') }}"></label>
<h2>Requested products</h2><x-request-items :products="$products" />
<label class="field"><span>Notes (optional)</span><textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea></label>
<div class="form-actions"><button class="primary-button" @disabled($customers->isEmpty()||$products->isEmpty())>Save request</button><a href="{{ route('sales-requests.index') }}" class="secondary-button">Cancel</a></div>
</form></section>
@endsection