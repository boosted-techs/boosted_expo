@extends('layouts.console')
@section('title','Receivables & Payables FX')
@section('content')
<div class="page-intro"><div><span class="eyebrow">FOREIGN CURRENCY ACCOUNTING</span>
<h1>Revalue customer and supplier balances</h1>
<p>Use approved exchange rates for outstanding foreign invoices and supplier bills, without changing their contractual amount.</p></div></div>
<div class="form-actions" style="margin-bottom:18px">
<a class="secondary-button" href="{{ route('finance.index') }}">Finance overview</a>
<a class="secondary-button" href="{{ route('finance.rates') }}">Approved exchange rates</a>
<a class="secondary-button" href="{{ route('finance.reviews') }}">Posting approvals</a></div>
<div class="notice"><span>●</span><div><strong>Base-currency accounting only</strong>
<p>This updates the valuation of the remaining foreign receivable or supplier liability. No customer is charged, no supplier is paid and no foreign bank money moves. Completed payments reclassify their share of accumulated unrealised gains or losses into realised FX.</p></div></div>
@if($canPrepare)
<section class="section-card"><h2>Prepare monetary-item revaluation</h2>
<form method="POST" action="{{ route('finance.monetary-revaluations.prepare') }}" class="form-stack form-narrow">@csrf
<label class="field"><span>Item type</span><select name="source_type" required>
<option value="commercial_invoice">Outstanding customer invoice</option>
<option value="supplier_bill">Outstanding supplier bill</option></select></label>
<label class="field"><span>Invoice or bill ID</span>
<input name="source_id" type="number" min="1" required placeholder="Select the ID from the tables below"></label>
<div class="form-columns">
<label class="field"><span>Valuation date</span>
<input type="date" name="valued_on" max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}" required></label>
<label class="field"><span>Approved closing rate</span>
<select name="exchange_rate_id" required><option value="">Select rate matching item's currency</option>
@foreach($rates as $rate)<option value="{{ $rate->id }}">{{ $rate->currency }} → {{ $business->base_currency }} · {{ $rate->rate_to_base }} · {{ $rate->effective_on->format('d M Y') }}</option>@endforeach
</select></label></div>
<label class="field"><span>Valuation evidence and supporting documentation</span>
<textarea name="valuation_evidence" rows="3" minlength="30" maxlength="3000" required placeholder="Approved source, dated FX rate, supporting invoice/bill and ledger valuation evidence"></textarea></label>
<button class="primary-button">Submit valuation for independent finance approval</button>
</form></section>
@endif
<section class="section-card"><h2>Outstanding customer invoices</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Reference</th><th>Currency</th><th>Still owed</th></tr></thead><tbody>
@forelse($invoices as $item)<tr><td>{{ $item->id }}</td><td>{{ $item->reference }}</td><td>{{ $item->currency }}</td>
<td>{{ app(\App\Services\FinanceDocuments::class)->outstanding($item,false) }}</td></tr>
@empty<tr><td colspan="4">No outstanding foreign invoices found.</td></tr>@endforelse
</tbody></table></div></section>
<section class="section-card"><h2>Outstanding supplier bills</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Reference</th><th>Currency</th><th>Still owed</th></tr></thead><tbody>
@forelse($bills as $item)<tr><td>{{ $item->id }}</td><td>{{ $item->reference }}</td><td>{{ $item->currency }}</td>
<td>{{ app(\App\Services\FinanceDocuments::class)->outstanding($item,false) }}</td></tr>
@empty<tr><td colspan="4">No outstanding foreign supplier bills found.</td></tr>@endforelse
</tbody></table></div></section>
<section class="section-card"><h2>Revaluation register</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Reference</th><th>Original item</th><th>Valuation</th><th>Previous value</th><th>Revalued value</th><th>Change</th><th>Status</th></tr></thead><tbody>
@forelse($records as $r)
<tr><td><strong>{{ $r->reference }}</strong></td><td>{{ ucwords(str_replace('_',' ',$r->source_type)) }} #{{ $r->source_id }}</td>
<td>{{ $r->valued_on->format('d M Y') }} · {{ $r->foreign_balance }} {{ $r->currency }} at {{ $r->rate_to_base }}</td>
<td>{{ $business->base_currency }} {{ $r->previous_base }}</td><td>{{ $business->base_currency }} {{ $r->revalued_base }}</td>
<td>{{ $business->base_currency }} {{ $r->change_base }}</td><td>{{ $r->posting_status==='posted'?'Posted':'Awaiting approval' }}</td></tr>
@empty<tr><td colspan="7" class="empty-msg">No foreign customer or supplier revaluations yet.</td></tr>@endforelse
</tbody></table></div>{{ $records->links() }}</section>
@endsection