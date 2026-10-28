@extends('layouts.console')
@section('title','Foreign Bank Revaluation')
@section('content')
<div class="page-intro"><div><span class="eyebrow">FOREIGN CURRENCY ACCOUNTING</span>
<h1>Foreign bank revaluation</h1><p>Update the book valuation of a foreign-currency bank asset at an approved historical rate, without recording a cash transfer.</p></div><span class="tiny-pill">{{ $business->base_currency }} BASE</span></div>
<div class="form-actions" style="margin-bottom:18px">
<a class="secondary-button" href="{{ route('finance.index') }}">Finance dashboard</a>
<a class="secondary-button" href="{{ route('finance.controls') }}">Opening balances</a>
<a class="secondary-button" href="{{ route('finance.rates') }}">Approved exchange rates</a>
<a class="secondary-button" href="{{ route('finance.reviews') }}">Independent posting reviews</a></div>
<div class="notice"><span>●</span><div><strong>Book valuation only — no money moves</strong>
<p>The foreign account balance stays in the original currency. A positive change increases the base-currency asset value and records an <em>unrealised</em> exchange gain; a negative change records an unrealised loss. Journal lines contain zero original-currency movement. This is not a foreign exchange purchase, sale, refund or bank transaction.</p></div></div>
@if(!$chartReady)
<div class="notice"><span>●</span><div><strong>Company chart update required</strong>
<p>The unrealised exchange gain and loss accounts have not yet been activated for this business. Ask an authorised business owner to use <a href="{{ route('finance.index') }}">Finance Dashboard → Add missing finance accounts</a> before preparing a revaluation. Existing journals will not be modified.</p></div></div>
@endif
@if($canPrepare && $chartReady)
<section class="section-card"><h2>Prepare a valuation for independent review</h2>
<p class="muted">Supported for positive assets in named foreign-currency bank accounts only. Use a rate that has already been approved for the chosen date. The selected valuation date cannot precede the latest ledger entry for that account, and a closed or pending financial period remains locked.</p>
@if($banks->isEmpty())
<p class="empty-msg">No named foreign bank accounts are configured. <a href="{{ route('finance.controls') }}">Configure company bank accounts</a> first.</p>
@else
<form method="POST" action="{{ route('finance.fx-revaluations.prepare') }}" class="form-stack form-narrow">@csrf
<div class="form-columns">
<label class="field"><span>Foreign bank account</span><select name="bank_profile_id" required>
<option value="">Choose bank</option>
@foreach($banks as $bank)
<option value="{{ $bank->id }}">{{ $bank->label }} · {{ $bank->currency }}</option>
@endforeach</select></label>
<label class="field"><span>Valuation date</span><input type="date" name="valued_on" required
max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}"></label></div>
<label class="field"><span>Approved closing exchange rate</span>
<select name="exchange_rate_id" required><option value="">Choose an approved rate for the bank currency</option>
@foreach($rates as $rate)
<option value="{{ $rate->id }}">{{ $rate->currency }} → {{ $business->base_currency }}
· {{ $rate->rate_to_base }} · Effective {{ $rate->effective_on->format('d M Y') }}
· {{ $rate->source_note }}</option>
@endforeach</select></label>
<label class="field"><span>Rate evidence and reason for revaluation</span>
<textarea name="valuation_evidence" rows="3" required minlength="30" maxlength="3000"
placeholder="State approved closing rate source, period, original bank amount, supporting statement and authorisation"></textarea></label>
<p class="form-hint">Review the foreign balance, new value and exchange difference, then request independent approval.</p>
<button class="primary-button">Prepare non-cash foreign bank revaluation</button>
</form>
@endif
</section>
@endif
<section class="section-card"><h2>Valuation register</h2>
<div class="table-wrap"><table class="data-table"><thead><tr>
<th>Reference / bank</th><th>Date / rate</th><th>Foreign balance</th>
<th>Previous book value</th><th>Revalued book value</th><th>Unrealised change</th><th>Status</th>
</tr></thead><tbody>
@forelse($records as $r)
<tr><td><strong>{{ $r->reference }}</strong><small>{{ $r->bankProfile?->label }}</small></td>
<td>{{ $r->valued_on->format('d M Y') }}<small>{{ $r->rate_to_base }} {{ $business->base_currency }} per {{ $r->currency }}</small></td>
<td>{{ $r->currency }} {{ $r->foreign_balance }}</td>
<td>{{ $business->base_currency }} {{ $r->previous_base }}</td>
<td>{{ $business->base_currency }} {{ $r->revalued_base }}</td>
<td>{{ $business->base_currency }} {{ $r->change_base }}</td>
<td>{{ $r->posting_status==='posted'?'Posted, no cash movement':'Pending finance approval' }}</td></tr>
@empty<tr><td colspan="7" class="empty-msg">No foreign bank revaluations have been prepared.</td></tr>@endforelse
</tbody></table></div>{{ $records->links() }}
</section>
<p class="form-hint">Only bank assets are currently supported. Receivables, payables, debt, advances, overdrafts, inventory and forward contracts require separate monetary-item valuation policy and journal categories. Revaluation does not update original bank-statement movements.</p>
@endsection