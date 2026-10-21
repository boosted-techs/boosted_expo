@extends('layouts.console')
@section('title','Transaction Corrections')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ACCOUNTING INTEGRITY</span>
<h1>Review posted payment corrections</h1>
<p>Reverse incorrect accounting records without deleting paid invoices, settlement evidence or historical journals.</p></div></div>
<div class="form-actions" style="margin-bottom:18px">
<a class="secondary-button" href="{{ route('finance.documents') }}">Payment records</a>
<a class="secondary-button" href="{{ route('finance.reviews') }}">Independent posting reviews</a>
<a class="secondary-button" href="{{ route('finance.bank.index') }}">Bank reconciliation</a></div>
<div class="notice"><span>●</span><div><strong>This does not undo a real bank transfer</strong>
<p>A correction requires a documented accounting error or actual externally evidenced return. Approval reverses the original ledger journal exactly, including any historical FX gain/loss, but does not refund a customer, recover a salary or instruct a bank. If the original payment has already been reconciled to a bank statement, import the separately evidenced return on the same named bank first. A different authorised person must approve the exact reversing journal and independently confirm the bank return match afterward. Reconcile the final balances before issuing documents externally.</p></div></div>
@if($canPrepare)
<section class="section-card"><h2>Eligible posted financial transactions</h2>
<p class="muted">Open a transaction, document the mistake or evidenced return, and submit a new reversing journal for independent finance approval. Never use this screen to conceal a legitimate settled payment.</p>
@php
$sourceGroups=[
 ['kind'=>'payment','name'=>'Customer receipts and supplier payments','rows'=>$payments],
 ['kind'=>'shipment_cost','name'=>'Recorded shipment cost payments','rows'=>$costs],
 ['kind'=>'payroll_settlement','name'=>'Salary settlements and deduction remittances','rows'=>$payroll],
];
@endphp
@foreach($sourceGroups as $group)
<h3 style="margin-top:22px">{{ $group['name'] }}</h3>
@forelse($group['rows'] as $source)
@php
 $originalMatch=$sourceMatches->get($group['kind'].':'.$source->id);
 $bankMatched=$originalMatch!==null;
 $bankCanCorrect=!$bankMatched || ($originalMatch->status==='confirmed'
     && $originalMatch->line?->statement?->status==='reconciled');
 $eligibleReturns=$returnLines->filter(fn($line)=>
     $bankMatched && $line->statement?->account_id===$source->cash_account_id
     && $line->statement?->currency===$source->currency
     && $line->direction!==$originalMatch->line?->direction
     && \Brick\Math\BigDecimal::of((string)$line->amount)->isLessThanOrEqualTo((string)$source->amount)
     && $line->transaction_on->toDateString()
         >=$originalMatch->line->transaction_on->toDateString()
     && !in_array($line->id,$reservedReturnIds,true)
     && !in_array($line->id,$reservedPartialIds,true)
 );
@endphp
<details class="finance-details">
<summary><strong>{{ $source->reference }}</strong> · {{ $source->currency }} {{ $source->amount }}
· {{ $source->external_reference }} · {{ $source->status }}</summary>
<div class="record"><span>Account / method</span><strong>{{ $source->cashAccount?->name?:'See original journal' }}</strong></div>
<div class="record"><span>Original payment date</span><strong>{{ ($source->payment_on??$source->paid_on)->format('d M Y') }}</strong></div>
@if($bankMatched)
<div class="notice"><span>●</span><div><strong>Original bank transaction matched</strong>
<p>{{ $originalMatch->status==='confirmed'?'Independently confirmed':'Match under independent review' }} ·
{{ $originalMatch->line?->statement?->reference }} ·
{{ $originalMatch->line?->bank_reference?:'No bank reference supplied' }}.
The original statement must be fully reconciled. Select the actual opposite-direction returned amount from an imported open statement. The system will distinguish a full return from a partial return and preserve both bank events.</p></div></div>
@endif
@if($bankCanCorrect)
<form method="POST" action="{{ route('finance.transaction-reversals.prepare',['type'=>$group['kind'],'id'=>$source->id]) }}"
class="form-stack form-narrow">@csrf
@if($bankMatched)
<label class="field"><span>Imported opposite-direction bank return — exact source amount</span>
<select name="return_bank_line_id" required>
<option value="">Choose a separately imported bank-return entry</option>
@foreach($eligibleReturns as $candidate)
<option value="{{ $candidate->id }}">{{ $candidate->statement->reference }}
· {{ $candidate->transaction_on->format('d M Y') }}
· {{ ucfirst($candidate->direction) }} {{ $candidate->statement->currency }}
{{ $candidate->amount }} · {{ $candidate->bank_reference?:'No reference' }}
</option>
@endforeach</select></label>
@if($eligibleReturns->isEmpty())
<p class="form-hint">No matching return line is available. Import the actual return using <a href="{{ route('finance.bank.index') }}">Bank Reconciliation</a> before preparing this correction.</p>
@endif
@endif
<label class="field"><span>Reason for correcting the recorded transaction</span>
<textarea name="reason" rows="3" minlength="30" maxlength="3000" required
placeholder="Explain why this recorded payment was posted incorrectly or why an externally evidenced reversal is required"></textarea></label>
<label class="field"><span>External correction, return or reversal evidence</span>
<textarea name="external_evidence" rows="3" minlength="30" maxlength="3000" required
placeholder="Provide banking evidence reference, cash correction voucher, original event and verification details"></textarea></label>
<button class="secondary-button" {{ $bankMatched && $eligibleReturns->isEmpty()?'disabled':'' }}>Prepare full or partial return for independent approval</button>
</form>
@else
<p class="form-hint">The original statement is awaiting confirmation or completion. Finish that review before recording a matched-payment return.</p>
@endif
</details>
@empty<p class="empty-msg">No posted transactions available in this category.</p>@endforelse
@endforeach
</section>
@endif
<section class="section-card"><h2>Full correction register</h2>
<div class="table-wrap"><table class="data-table"><thead><tr>
<th>Reference</th><th>Source</th><th>Amount</th><th>Bank-return evidence</th><th>Original journal</th><th>Review</th><th>Reason</th></tr></thead><tbody>
@forelse($reversals as $item)
<tr><td><strong>{{ $item->reference }}</strong></td>
<td>{{ ucwords(str_replace('_',' ',$item->source_type)) }} #{{ $item->source_id }}</td>
<td>{{ $item->currency }} {{ $item->amount }}</td>
<td>{{ $item->return_bank_line_id?'Imported bank line #'.$item->return_bank_line_id:'No prior bank match' }}</td>
<td><a class="action-link" href="{{ route('finance.journal',$item->original_journal_id) }}">Original approved entry</a></td>
<td>{{ $item->posting_status==='posted'?'Corrected in ledger':'Awaiting independent posting' }}</td>
<td>{{ $item->reason }}</td></tr>
@empty<tr><td colspan="7" class="empty-msg">No payment corrections have been prepared.</td></tr>@endforelse
</tbody></table></div>{{ $reversals->links() }}
</section>
<section class="section-card"><h2>Partial bank return register</h2>
<p class="muted">These adjustments retain the original transaction and restore only the amount actually returned. Further returns against the same original are allowed one at a time after each earlier adjustment posts.</p>
<div class="table-wrap"><table class="data-table"><thead><tr>
<th>Reference</th><th>Original transaction</th><th>Returned</th><th>Bank evidence</th><th>Review</th></tr></thead><tbody>
@forelse($partialReturns as $partial)
<tr><td>{{ $partial->reference }}</td>
<td>{{ ucwords(str_replace('_',' ',$partial->source_type)) }} #{{ $partial->source_id }}</td>
<td>{{ $partial->currency }} {{ $partial->amount }}</td>
<td>Imported return line #{{ $partial->return_bank_line_id }} · {{ $partial->data_snapshot['return_date'] }}</td>
<td>{{ $partial->posting_status==='posted'?'Posted, independently reviewed':'Awaiting independent finance approval' }}</td></tr>
@empty<tr><td colspan="5" class="empty-msg">No partial bank returns recorded.</td></tr>@endforelse
</tbody></table></div></section>
@endsection