@extends('layouts.console')
@section('title','Full Credit Note')
@section('content')
<div class="page-intro"><div><span class="eyebrow">COMMERCIAL CORRECTION</span><h1>{{ $note->reference }}</h1><p>{{ $note->invoice->reference }} · {{ $note->currency }} {{ $note->amount }}</p></div><span class="tiny-pill">{{ $note->posting_status==='posted'?'POSTED':'AWAITING APPROVAL' }}</span></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.credits.index') }}">Credit notes</a><a class="secondary-button" href="{{ route('commercial-invoices.show',$note->commercial_invoice_id) }}">Original invoice</a><a class="secondary-button" href="{{ route('finance.reviews') }}">Approvals</a>
@if($note->posting_status==='posted')<a class="primary-button" href="{{ route('finance.credits.pdf',$note->id) }}">Download issued PDF</a>
<a class="secondary-button" href="{{ route('finance.credits.print',$note->id) }}" target="_blank" rel="noopener noreferrer">Print</a>@endif</div>
<section class="section-card"><h2>Correction details</h2>
<div class="record"><strong>Original commercial invoice</strong><span>{{ $note->data_snapshot['invoice_reference'] }}</span></div>
<div class="record"><strong>Buyer</strong><span>{{ $note->invoice->customer->name }}</span></div>
<div class="record"><strong>Credit value</strong><strong>{{ $note->currency }} {{ $note->amount }}</strong></div>
<div class="record"><strong>Original posted exchange rate</strong><span>{{ $note->data_snapshot['original_rate'] }}</span></div>
<div class="record"><strong>Reason</strong><span>{{ $note->reason }}</span></div>
<div class="record"><strong>Accounting status</strong><span>{{ $note->posting_status }}</span></div>
</section>
<div class="notice"><span>●</span><div><strong>What this correction does not do</strong><p>It will not change the printed original invoice, shipment allocations, payment statements, stock movement or customs documents. Replacement invoices, refunds, partial credits and cargo returns require their own reviewed workflows.</p></div></div>
@endsection