@extends('layouts.console')
@section('title','Bank Statement')
@section('content')
<div class="page-intro"><div><span class="eyebrow">BANK STATEMENT REVIEW</span><h1>{{ $statement->reference }}</h1><p>{{ $statement->currency }} · {{ $statement->period_from->format('d M Y') }} to {{ $statement->period_to->format('d M Y') }} · {{ ucfirst($statement->status) }}</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.bank.index') }}">All statements</a><a class="secondary-button" href="{{ route('finance.documents') }}">Posted payment records</a></div>
<div class="notice"><span>●</span><div><strong>Every line needs an independently confirmed match</strong><p>Choose a posted payment, shipment cost or payroll settlement for each bank line. The system requires the same currency, account, direction and exact amount. Transactions are not verified automatically.</p></div></div>
<section class="section-card"><h2>Statement details</h2>
<div class="record"><strong>Currency</strong><span>{{ $statement->currency }}</span></div>
<div class="record"><strong>Opening → Closing</strong><span>{{ $statement->opening_balance }} → {{ $statement->closing_balance }}</span></div>
<div class="record"><strong>Credits / debits</strong><span>{{ $statement->credits }} / {{ $statement->debits }}</span></div>
<div class="record"><strong>Matched lines</strong><span>{{ $matched }} of {{ $statement->lines()->count() }}</span></div>
<div class="record"><strong>Original filename</strong><span>{{ $statement->filename }}</span></div>
@if($canReview && $statement->status==='open' && $matched===$statement->lines()->count() && $statement->imported_by!==auth()->id())
<form method="POST" action="{{ route('finance.bank.complete',$statement->id) }}" style="margin-top:12px">@csrf<button class="primary-button">Close fully reconciled statement</button></form>
@endif
</section>
@if($statement->status==='reconciled')
<section class="section-card"><h2>Independent bank-to-ledger balance proof</h2>
<p class="muted">Matching vouchers to uploaded statement lines does not automatically prove the book balance. This second control compares the bank statement opening/closing values with posted ledger movements in the original bank currency, checks that every posted movement in this period has a confirmed matching bank line, and freezes its evidence for separate review.</p>
@if($ledgerPreview)
<div class="table-wrap"><table class="data-table"><thead><tr><th>Check</th><th>Uploaded bank statement</th><th>Posted bank ledger</th><th>Variance (ledger − statement)</th></tr></thead><tbody>
<tr><td>Opening balance</td><td>{{ $statement->currency }} {{ $ledgerPreview['statement_opening'] }}</td>
<td>{{ $statement->currency }} {{ $ledgerPreview['book_opening'] }}</td>
<td>{{ $ledgerPreview['opening_variance'] }}</td></tr>
<tr><td>Closing balance</td><td>{{ $statement->currency }} {{ $ledgerPreview['statement_closing'] }}</td>
<td>{{ $statement->currency }} {{ $ledgerPreview['book_closing'] }}</td>
<td>{{ $ledgerPreview['closing_variance'] }}</td></tr>
<tr><td>Bank transaction coverage</td><td>{{ $ledgerPreview['statement_line_count'] }} confirmed bank lines</td>
<td>{{ $ledgerPreview['ledger_movement_count'] }} dated ledger movements</td>
<td>{{ $ledgerPreview['movement_matches']?'All movements agree':'Movement reference or direction mismatch' }}</td></tr>
</tbody></table></div>
<p class="form-hint">Base-currency valuation equivalent: {{ $statement->currency }} opening translates to {{ $ledgerPreview['base_currency'] }} {{ $ledgerPreview['book_base_opening'] }}, and closing to {{ $ledgerPreview['base_currency'] }} {{ $ledgerPreview['book_base_closing'] }} in the posted accounting records. These are historical ledger values, not an FX revaluation at today's rate.</p>
@if($ledgerCheck)
<div class="record"><div><strong>Independent book proof: {{ ucfirst($ledgerCheck->status) }}</strong>
<small>{{ $ledgerCheck->review_notes?:'Awaiting an independent review note.' }}</small></div>
<span class="tiny-pill">{{ $isLedgerCheckCurrent?'BOOK BALANCE VERIFIED':($ledgerCheck->status==='verified'?'OUTDATED PROOF':'NOT YET VERIFIED') }}</span></div>
@endif
@if($canProofSubmit && (!$ledgerCheck || $ledgerCheck->status==='rejected' ||
($ledgerCheck->status==='verified' && !$isLedgerCheckCurrent)) &&
$ledgerPreview['opening_matches'] && $ledgerPreview['closing_matches'] &&
$ledgerPreview['movement_matches'])
<form method="POST" action="{{ route('finance.bank.proof.submit',$statement->id) }}"
class="form-stack form-narrow" style="margin-top:12px">@csrf
<label class="field"><span>Source-ledger reconciliation work performed</span>
<textarea name="notes" rows="2" minlength="20" maxlength="2000" required
placeholder="Describe the statement, chart account, opening-balance evidence and journal-date verification"></textarea></label>
<button class="secondary-button">Request independent ledger verification</button></form>
@endif
@if($ledgerCheck && $ledgerCheck->status==='pending' && $canProofReview &&
$ledgerCheck->prepared_by!==auth()->id())
<div class="form-columns">
<form method="POST" action="{{ route('finance.bank.proof.review',['check'=>$ledgerCheck->id,'decision'=>'approve']) }}" class="form-stack">@csrf
<label class="field"><span>Independent approval evidence</span>
<input name="notes" minlength="15" maxlength="2000" required placeholder="Statement and original-currency opening/closing reviewed"></label>
<button class="primary-button">Verify matching bank and ledger balances</button></form>
<form method="POST" action="{{ route('finance.bank.proof.review',['check'=>$ledgerCheck->id,'decision'=>'reject']) }}" class="form-stack">@csrf
<label class="field"><span>Reason for rejecting book balance</span>
<input name="notes" minlength="15" maxlength="2000" required placeholder="Source evidence or ledger not sufficiently verified"></label>
<button class="secondary-button">Reject book balance proof</button></form></div>
@endif
@if(!$ledgerPreview['opening_matches'] || !$ledgerPreview['closing_matches'] ||
 !$ledgerPreview['movement_matches'])
<p class="form-hint">Book-balance proof cannot be requested until the opening and closing amounts and every posted bank movement agree with the statement. No automatic balancing adjustment will be created.</p>
@endif
@else
<p class="form-hint">A full balance proof requires a named, single-currency bank ledger with traceable posted source transactions. Shared historical accounts with mixed currencies require controlled reconstruction before proof can be requested.</p>
@endif
<p class="form-hint">This is an independent internal accounting check only. It does not authenticate the uploaded CSV against a bank or replace bank-issued confirmation.</p>
</section>
@endif
<section class="section-card"><h2>Transaction lines</h2>
@forelse($lines as $line)
<div class="finance-details" style="margin:12px 0;padding:12px;border:1px solid var(--line);border-radius:12px">
<div class="record"><div><strong>{{ $line->transaction_on->format('d M Y') }} · {{ ucfirst($line->direction) }} {{ $statement->currency }} {{ $line->amount }}</strong>
<small>{{ $line->bank_reference?:'No bank reference' }} · {{ $line->description }}</small></div>
<span class="tiny-pill">{{ $line->match ? ucfirst($line->match->status) : 'Unmatched' }}</span></div>
@if($statement->status==='open' && $canMatch && (!$line->match || $line->match->status==='rejected'))
<form method="POST" action="{{ route('finance.bank.match',['statement'=>$statement->id,'line'=>$line->id]) }}" class="form-stack" style="margin:10px 0">@csrf
<label class="field"><span>Matching posted transaction</span><select name="candidate" required><option value="">Choose a matching posted transaction</option>
@foreach($payments as $p)@if($p->direction===($line->direction==='credit'?'in':'out') && \Brick\Math\BigDecimal::of((string)$p->amount)->isEqualTo((string)$line->amount))
<option value="payment:{{ $p->id }}">Payment {{ $p->reference }} · {{ $p->currency }} {{ $p->amount }}</option>@endif @endforeach
@foreach($costs as $cost)@if($line->direction==='debit' && \Brick\Math\BigDecimal::of((string)$cost->amount)->isEqualTo((string)$line->amount))
<option value="shipment_cost:{{ $cost->id }}">Cost {{ $cost->reference }} · {{ $cost->currency }} {{ $cost->amount }}</option>@endif @endforeach
@foreach($payrollSettlements as $settlement)@if($line->direction==='debit' && \Brick\Math\BigDecimal::of((string)$settlement->amount)->isEqualTo((string)$line->amount))
<option value="payroll_settlement:{{ $settlement->id }}">Payroll {{ $settlement->reference }} · {{ $settlement->currency }} {{ $settlement->amount }}</option>@endif @endforeach
@foreach($reversals as $reversal)
@php
$bankEntry=collect($reversal->original_snapshot['lines']??[])
    ->firstWhere('account_id',$statement->account_id);
$opposite=$bankEntry
    ?(\Brick\Math\BigDecimal::of((string)$bankEntry['debit'])->isGreaterThan(0)
        ?'debit':'credit'):null;
@endphp
@if($opposite===$line->direction &&
\Brick\Math\BigDecimal::of((string)$reversal->amount)->isEqualTo((string)$line->amount))
<option value="transaction_reversal:{{ $reversal->id }}">Payment correction {{ $reversal->reference }} · {{ $reversal->currency }} {{ $reversal->amount }}</option>
@endif
@endforeach
</select><small>Posted transactions and their separately approved corrections are offered when amount, currency and direction agree. An original payment that was later reversed remains historical bank evidence. Verify the bank reference too.</small></label>
<label class="field"><span>Why do these entries match?</span><input name="note" minlength="10" maxlength="1000" required placeholder="Bank reference, date, transaction evidence"></label>
<button class="secondary-button">Propose match</button></form>
@endif
@if($line->match && $line->match->status==='pending' && $canReview && $line->match->proposed_by!==auth()->id())
<div class="form-columns" style="margin-top:12px">
<form method="POST" action="{{ route('finance.bank.confirm',$line->match->id) }}" class="form-stack">@csrf
<label class="field"><span>Confirmation reason</span><input name="note" minlength="10" maxlength="1000" required placeholder="Verified bank reference and voucher"></label>
<button class="primary-button">Confirm reconciliation</button></form>
<form method="POST" action="{{ route('finance.bank.reject',$line->match->id) }}" class="form-stack">@csrf
<label class="field"><span>Reason for rejection</span><input name="note" minlength="10" maxlength="1000" required placeholder="Wrong bank reference or source"></label>
<button class="secondary-button">Reject match</button></form>
</div>
@endif
</div>
@empty<p class="empty-msg">This statement contains no transaction lines.</p>@endforelse
{{ $lines->links() }}
</section>
@endsection