@extends('layouts.console')
@section('title','Invoice Corrections')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CONTROLLED INVOICE CORRECTIONS</span><h1>Full credit notes</h1><p>Preserve the original commercial invoice and reverse its accounting journal only after an independent approval.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.index') }}">Finance dashboard</a><a class="secondary-button" href="{{ route('commercial-invoices.index') }}">Commercial invoices</a><a class="secondary-button" href="{{ route('finance.reviews') }}">Posting approvals</a></div>
<div class="notice"><span>●</span><div><strong>Full credits only — with no active customer payments</strong><p>This workflow corrects a posted invoice in full. It creates a reversing journal that preserves the original posting and FX rate, rather than editing or deleting the original. It does not automatically return shipped goods, reopen cartons, produce a replacement invoice, process a refund or make a tax adjustment.</p></div></div>
@if($canPrepare)
<section class="section-card"><h2>Prepare full invoice correction</h2>
<p class="muted">Only eligible posted invoices without pending or completed customer receipts are available. Select the invoice, record an adequate explanation, and submit it for approval.</p>
@if($invoices->isEmpty())<p class="empty-msg">No posted invoices are eligible for a full credit note.</p>@endif
@foreach($invoices as $invoice)
<details class="finance-details"><summary><strong>{{ $invoice->reference }}</strong> · {{ $invoice->customer->name }} · {{ $invoice->currency }} {{ $invoice->grand_total }}</summary>
<form method="POST" action="{{ route('finance.credits.prepare',$invoice->id) }}" class="form-stack">@csrf
<label class="field"><span>Detailed correction reason</span><textarea name="reason" rows="3" required minlength="20" maxlength="3000" placeholder="Explain why the full invoice must be credited and reference the buyer authorisation or error."></textarea></label>
<button class="secondary-button" onclick="return confirm('Prepare a FULL credit correction for this invoice? This does not refund any money.')">Prepare full credit note</button></form></details>
@endforeach
</section>
@endif
<section class="section-card"><h2>Credit note history</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Credit note</th><th>Original invoice</th><th>Buyer</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($notes as $note)
<tr><td><strong>{{ $note->reference }}</strong></td><td>{{ $note->invoice->reference }}</td><td>{{ $note->invoice->customer->name }}</td>
<td>{{ $note->currency }} {{ $note->amount }}</td><td><span class="tiny-pill">{{ $note->posting_status==='posted'?'Credited & posted':'Awaiting approval' }}</span></td>
<td><a class="action-link" href="{{ route('finance.credits.show',$note->id) }}">Open</a></td></tr>
@empty<tr><td colspan="6" class="empty-msg">No credit corrections recorded yet.</td></tr>@endforelse
</tbody></table></div>{{ $notes->links() }}</section>
@endsection