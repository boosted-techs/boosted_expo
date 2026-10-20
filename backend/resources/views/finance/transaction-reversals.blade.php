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
<p>A correction requires a documented accounting error or actual externally evidenced return. Approval reverses the original ledger journal exactly, including any historical FX gain/loss, but does not refund a customer, recover a salary or instruct a bank. Bank-matched payments are blocked: their original bank evidence must be addressed through a dedicated controlled bank correction first. Reconcile the final balances before issuing documents externally.</p></div></div>
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
<details class="finance-details">
<summary><strong>{{ $source->reference }}</strong> · {{ $source->currency }} {{ $source->amount }}
· {{ $source->external_reference }} · {{ $source->status }}</summary>
<div class="record"><span>Account / method</span><strong>{{ $source->cashAccount?->name?:'See original journal' }}</strong></div>
<div class="record"><span>Original payment date</span><strong>{{ ($source->payment_on??$source->paid_on)->format('d M Y') }}</strong></div>
<form method="POST" action="{{ route('finance.transaction-reversals.prepare',['type'=>$group['kind'],'id'=>$source->id]) }}"
class="form-stack form-narrow">@csrf
<label class="field"><span>Reason for correcting the recorded transaction</span>
<textarea name="reason" rows="3" minlength="30" maxlength="3000" required
placeholder="Explain why this recorded payment was posted incorrectly or why an externally evidenced reversal is required"></textarea></label>
<label class="field"><span>External correction, return or reversal evidence</span>
<textarea name="external_evidence" rows="3" minlength="30" maxlength="3000" required
placeholder="Provide banking evidence reference, cash correction voucher, original event and verification details"></textarea></label>
<button class="secondary-button">Prepare reversal for independent approval</button>
</form></details>
@empty<p class="empty-msg">No posted transactions available in this category.</p>@endforelse
@endforeach
</section>
@endif
<section class="section-card"><h2>Correction register</h2>
<div class="table-wrap"><table class="data-table"><thead><tr>
<th>Reference</th><th>Source</th><th>Amount</th><th>Original journal</th><th>Review</th><th>Reason</th></tr></thead><tbody>
@forelse($reversals as $item)
<tr><td><strong>{{ $item->reference }}</strong></td>
<td>{{ ucwords(str_replace('_',' ',$item->source_type)) }} #{{ $item->source_id }}</td>
<td>{{ $item->currency }} {{ $item->amount }}</td>
<td><a class="action-link" href="{{ route('finance.journal',$item->original_journal_id) }}">Original approved entry</a></td>
<td>{{ $item->posting_status==='posted'?'Corrected in ledger':'Awaiting independent posting' }}</td>
<td>{{ $item->reason }}</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No payment corrections have been prepared.</td></tr>@endforelse
</tbody></table></div>{{ $reversals->links() }}
</section>
@endsection