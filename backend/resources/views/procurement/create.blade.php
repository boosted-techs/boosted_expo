@extends('layouts.console')
@section('title','New Supply Request')
@section('content')
<div class="page-intro"><div><span class="eyebrow">BUYING</span><h1>New supply request</h1></div></div>
<section class="section-card"><form method="POST" action="{{ route('supply-requests.store') }}" class="form-stack">@csrf
<x-request-items :products="$products" :limit="50" />
<div class="form-columns"><label class="field"><span>Currency</span><x-currency-select :value="old('currency','UGX')" /></label><label class="field"><span>Needed by (optional)</span><input name="needed_on" type="date" value="{{ old('needed_on') }}"></label><label class="field"><span>Replies close</span><input name="response_deadline" type="datetime-local" required value="{{ old('response_deadline') }}"></label></div>
<label class="field"><span>Notes for suppliers (optional)</span><textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea></label><button class="primary-button">Save request</button>
</form></section>
@endsection