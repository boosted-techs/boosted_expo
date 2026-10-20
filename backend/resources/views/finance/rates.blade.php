@extends('layouts.console')
@section('title','Exchange Rates')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CURRENCY CONTROL</span><h1>Historical exchange rates</h1><p>Set manually sourced currency conversion rates, independently approve them and preserve each posting's rate.</p></div><span class="tiny-pill">1 FOREIGN UNIT → {{ $business->base_currency }}</span></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.index') }}">Finance dashboard</a><a class="secondary-button" href="{{ route('finance.reviews') }}">Posting queue</a></div>
<div class="notice"><span>●</span><div><strong>Rates are entered manually</strong><p>Enter the number of {{ $business->base_currency }} units equal to one unit of the foreign currency, with an identifiable source and effective date. This is not a live exchange feed. A second authorised person must approve each rate.</p></div></div>
@if($canPropose)
<section class="section-card form-narrow"><h2>Propose exchange rate</h2>
<form method="POST" action="{{ route('finance.rates.propose') }}" class="form-stack">@csrf
<div class="form-columns"><label class="field"><span>Foreign currency (3 letters)</span><input name="currency" required maxlength="3" minlength="3" placeholder="USD" value="{{ old('currency') }}"></label>
<label class="field"><span>1 currency unit equals how many {{ $business->base_currency }}?</span><input name="rate_to_base" type="number" required min="0.0000000001" step="0.0000000001" value="{{ old('rate_to_base') }}" placeholder="e.g. 3650.50"></label></div>
<label class="field"><span>Effective date</span><input type="date" name="effective_on" value="{{ old('effective_on',now()->format('Y-m-d')) }}" required max="{{ now()->format('Y-m-d') }}"></label>
<label class="field"><span>Rate source / reference</span><input name="source_note" required minlength="8" maxlength="250" value="{{ old('source_note') }}" placeholder="e.g. Bank quote, reference and captured time"></label>
<button class="primary-button">Propose rate for approval</button></form>
</section>
@endif
<section class="section-card"><h2>Exchange rate register</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Foreign currency</th><th>Base currency</th><th>Rate</th><th>Effective on</th><th>Status</th><th>Source</th><th>Action</th></tr></thead><tbody>
@forelse($rates as $rate)
<tr><td><strong>{{ $rate->currency }}</strong></td><td>{{ $rate->base_currency }}</td><td>{{ $rate->rate_to_base }}</td>
<td>{{ $rate->effective_on->format('d M Y') }}</td><td><span class="tiny-pill">{{ ucfirst($rate->status) }}</span></td><td>{{ $rate->source_note }}</td>
<td>@if($rate->status==='proposed' && $canApprove && $rate->created_by!==auth()->id())
<form method="POST" action="{{ route('finance.rates.approve',$rate->id) }}">@csrf<button class="secondary-button">Approve</button></form>
@else<span class="form-hint">{{ $rate->status==='approved'?'Locked':'—' }}</span>@endif</td></tr>
@empty<tr><td colspan="7" class="empty-msg">No exchange rates recorded. The company base currency always uses rate 1.</td></tr>@endforelse
</tbody></table></div>{{ $rates->links() }}</section>
@endsection