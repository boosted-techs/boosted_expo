@extends('layouts.console')
@section('title','Finance Posting Reviews')
@section('content')
<div class="page-intro"><div><span class="eyebrow">FINANCIAL APPROVALS</span><h1>Review and post transactions</h1><p>Check source documents, exchange-rate evidence and business-event dates before approving their double-entry journals.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.index') }}">Trial balance</a><a class="secondary-button" href="{{ route('finance.documents') }}">Source documents</a><a class="secondary-button" href="{{ route('finance.rates') }}">Approved exchange rates</a>
<a class="secondary-button" href="{{ route('finance.inventory.index') }}">Inventory valuations</a>
<a class="secondary-button" href="{{ route('finance.credits.index') }}">Credit notes</a>
<a class="secondary-button" href="{{ route('finance.controls') }}">Opening, concessions & periods</a>
<a class="secondary-button" href="{{ route('finance.payroll.index') }}">Payroll</a>
<a class="secondary-button" href="{{ route('finance.transaction-reversals.index') }}">Payment corrections</a>
<a class="secondary-button" href="{{ route('finance.fx-revaluations.index') }}">Foreign bank revaluation</a>
<a class="secondary-button" href="{{ route('finance.monetary-revaluations.index') }}">Receivables & payables FX</a></div>
<div class="notice"><span>●</span><div><strong>Separation of duties is mandatory</strong><p>The person proposing a posting cannot approve it; the approver cannot post it. Posted journals cannot be edited. An invoice may only be proposed for revenue recognition after shipment dispatch is recorded, and the reviewer must assess the agreed terms and transfer evidence. For foreign currencies, select an approved historical rate effective no later than the posting date.</p></div></div>
@if($canSubmit)
<section class="section-card"><h2>Source documents awaiting finance posting</h2>
<p class="muted">Submitting a document here does not affect the ledger. Enter a meaningful audit explanation and, where applicable, the exact approved exchange rate.</p>
@include('finance._record-search', ['field' => 'source_search', 'page' => 'page', 'label' => 'Find source document by reference'])
@php $sourceSections=[
 ['type'=>'money_operation','name'=>'Deposits, advances, expenses and transfers','rows'=>$operations],
 ['type'=>'commercial_invoice','name'=>'Commercial invoices (revenue recognition)','rows'=>$invoices],
 ['type'=>'supplier_bill','name'=>'Supplier bills (accepted produce and payables)','rows'=>$bills],
 ['type'=>'payment','name'=>'Recorded customer and supplier payments','rows'=>$payments],
 ['type'=>'shipment_cost','name'=>'Recorded paid shipment costs','rows'=>$costs],
 ['type'=>'inventory_relief','name'=>'Dispatched COGS and inventory waste write-offs','rows'=>$reliefs],
 ['type'=>'credit_note','name'=>'Full credit-note corrections','rows'=>$creditNotes],
 ['type'=>'opening_balance','name'=>'Company opening balances','rows'=>$openingBalances],
 ['type'=>'balance_adjustment','name'=>'Partial buyer or supplier price concessions','rows'=>$balanceAdjustments],
 ['type'=>'payroll_run','name'=>'Staff payroll accruals','rows'=>$payrollRuns],
 ['type'=>'payroll_settlement','name'=>'Recorded salary payments & deduction remittances','rows'=>$payrollSettlements],
 ['type'=>'payroll_reversal','name'=>'Corrective payroll reversals (no settled payroll)','rows'=>$payrollReversals],
 ['type'=>'transaction_reversal','name'=>'Controlled corrections of posted payment journals','rows'=>$transactionReversals],
 ['type'=>'foreign_bank_opening','name'=>'Historical foreign-currency bank opening journals','rows'=>$foreignBankOpenings],
 ['type'=>'fx_revaluation','name'=>'Non-cash foreign bank FX revaluations','rows'=>$fxRevaluations],
 ['type'=>'partial_bank_return','name'=>'Reviewed partial bank returns','rows'=>$partialReturns],
 ['type'=>'monetary_revaluation','name'=>'Revalue foreign receivables and payables','rows'=>$monetaryRevaluations],
 ]; @endphp
@foreach($sourceSections as $section)
<h3 style="margin-top:24px">{{ $section['name'] }}</h3>
@forelse($section['rows'] as $source)
@php
 $id=$section['type'].':'.$source->id;
 $currency=$source->currency;
 $amount=match($section['type']){
  'commercial_invoice'=>$source->grand_total,
  'supplier_bill'=>$source->total,
  'inventory_relief'=>$source->base_amount,
  'opening_balance'=>$source->data_snapshot['debits'],
  'payroll_run'=>$source->gross_total,
  'payroll_reversal'=>$source->run?->gross_total,
  'payroll_settlement'=>$source->amount,
  'foreign_bank_opening'=>$source->foreign_amount,
  'fx_revaluation'=>(string)\Brick\Math\BigDecimal::of((string)$source->change_base)->abs()->toScale(2).' '.$business->base_currency,
  'monetary_revaluation'=>(string)\Brick\Math\BigDecimal::of((string)$source->change_base)->abs()->toScale(2).' '.$business->base_currency,
  default=>$source->amount,
 };
 $eligible=$section['type']!=='commercial_invoice' ||
  in_array($source->shipment?->status,['dispatched','delivered'],true);
@endphp
<details class="finance-details">
<summary><strong>{{ $source->reference }}</strong> · {{ $currency }} {{ $amount }} ·
@if($eligible && !in_array($id,$hasPending)) Ready for submission
@elseif(!$eligible) Awaiting recorded dispatch
@else Review already submitted @endif</summary>
@if($eligible && !in_array($id,$hasPending))
<form method="POST" action="{{ route('finance.reviews.request',['type'=>$section['type'],'source'=>$source->id]) }}" class="form-stack">@csrf
<label class="field"><span>Journal / recognition date</span><input type="date" name="posting_on" value="{{ $section['type']==='money_operation' ? $source->event_on->toDateString() : (in_array($section['type'],['fx_revaluation','monetary_revaluation'],true) ? $source->valued_on->format('Y-m-d') : (in_array($section['type'],['transaction_reversal','partial_bank_return'],true) && $source->return_bank_line_id ? ($section['type']==='transaction_reversal' ? $source->original_snapshot['bank_return_evidence']['return_transaction_on'] : $source->data_snapshot['return_date']) : now()->format('Y-m-d'))) }}" required max="{{ now()->format('Y-m-d') }}"></label>
@if(in_array($section['type'],['credit_note','balance_adjustment','transaction_reversal','partial_bank_return'],true))
<p class="form-hint">This correction must preserve the exact original approved exchange rate. A second rate cannot be selected.</p>
@elseif(in_array($section['type'],['money_operation','foreign_bank_opening','fx_revaluation','monetary_revaluation'],true))
<input type="hidden" name="exchange_rate_id" value="{{ $source->exchange_rate_id }}">
<p class="form-hint">Prepared approved rate: 1 {{ $currency }} = {{ $source->rate_to_base }} {{ $business->base_currency }} (locked rate reference #{{ $source->exchange_rate_id }}). It cannot be changed. For revaluation, choose the exact valuation date rather than today's date.</p>
@elseif($currency!==$business->base_currency)
<label class="field"><span>Approved conversion rate for {{ $currency }} → {{ $business->base_currency }}</span>
<select name="exchange_rate_id" required><option value="">Choose a locked historical rate</option>
@foreach($rates as $rate)@if($rate->currency===$currency)
<option value="{{ $rate->id }}">{{ $rate->effective_on->format('d M Y') }} · 1 {{ $currency }} = {{ $rate->rate_to_base }} {{ $business->base_currency }} · {{ $rate->source_note }}</option>
@endif @endforeach
</select></label>
@else<p class="form-hint">The business base currency uses an automatic rate of 1.</p>@endif
<label class="field"><span>{{ $section['type']==='commercial_invoice' ? 'Evidence for recognising revenue (mandatory)' : 'Supporting documents and accounting basis (mandatory)' }}</span>
<textarea name="basis_notes" required minlength="20" maxlength="3000" rows="3" placeholder="State the document, date, agreement, reference and the business reason for posting."></textarea></label>
<button class="primary-button">Submit for independent approval</button></form>
@else
<p class="form-hint">{{ $eligible ? 'Check the approval register below. Rejected reviews may be resubmitted when the source is still eligible.' : 'Finance cannot recognise revenue from a shipment still being prepared. Dispatch must be recorded and transfer evidence reviewed.' }}</p>
@endif
</details>
@empty<p class="empty-msg">No outstanding documents in this category.</p>@endforelse
{{ $section['rows']->links() }}
@endforeach
</section>
@endif
<section class="section-card"><h2>Posting review register</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Document</th><th>Posting date</th><th>Status</th><th>Exchange rate</th><th>Evidence</th><th>Controls</th></tr></thead><tbody>
@forelse($requests as $review)
<tr><td>{{ ucwords(str_replace('_',' ',$review->source_type)) }}</td><td>@if($review->source_type==='money_operation')<a href="{{ route('finance.money.show',$review->source_id) }}">Money record #{{ $review->source_id }}</a>@else #{{ $review->source_id }} @endif</td><td>{{ $review->posting_on->format('d M Y') }}</td>
<td><span class="tiny-pill">{{ ucfirst($review->status) }}</span></td>
<td>{{ $review->exchangeRate ? ($review->exchangeRate->currency.' @ '.$review->exchangeRate->rate_to_base) : 'Base currency' }}</td>
<td><small>{{ $review->basis_notes }}</small></td>
<td>
@if($review->status==='pending' && $canApprove && $review->requested_by!==auth()->id())
<form method="POST" action="{{ route('finance.reviews.approve',$review->id) }}" class="form-stack" style="min-width:175px">@csrf
<input name="notes" maxlength="2000" minlength="10" required placeholder="Reviewer evidence / decision">
<button class="secondary-button">Approve review</button></form>
<form method="POST" action="{{ route('finance.reviews.reject',$review->id) }}" class="form-stack" style="margin-top:6px">@csrf
<input name="notes" maxlength="2000" minlength="10" required placeholder="Reason for rejection">
<button class="text-button">Reject</button></form>
@elseif($review->status==='approved' && $canPost && $review->approved_by!==auth()->id())
<form method="POST" action="{{ route('finance.reviews.post',$review->id) }}"
data-confirm="Post this independently approved journal? Ledger postings cannot be edited.">@csrf
<button class="primary-button">Post journal</button></form>
@elseif($review->status==='posted')
<span class="form-hint">Posted — locked</span>
@else<span class="form-hint">Awaiting another authorised person</span>
@endif
</td></tr>
@empty<tr><td colspan="7" class="empty-msg">No finance posting reviews yet.</td></tr>@endforelse
</tbody></table></div>{{ $requests->links() }}
</section>
@endsection
