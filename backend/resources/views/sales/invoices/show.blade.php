@extends('layouts.console')
@section('title','Commercial Invoice')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ISSUED COMMERCIAL INVOICE</span><h1>{{ $invoice->reference }}</h1><p>{{ $invoice->customer->name }} · {{ $invoice->currency }} {{ $invoice->grand_total }}</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a href="{{ route('commercial-invoices.index') }}" class="secondary-button">All invoices</a>
<a href="{{ route('commercial-invoices.print',$invoice->id) }}" class="secondary-button" target="_blank" rel="noopener noreferrer">Print</a>
<a href="{{ route('commercial-invoices.pdf',$invoice->id) }}" class="primary-button">Download PDF</a></div>
<section class="section-card"><h2>Invoice details</h2>
<div class="record"><strong>Buyer</strong><span>{{ $invoice->customer->name }}</span></div>
<div class="record"><strong>Confirmed order</strong><a class="action-link" href="{{ route('sales-orders.show',$invoice->sales_order_id) }}">{{ $invoice->order->reference }}</a></div>
<div class="record"><strong>Shipment</strong><a class="action-link" href="{{ route('shipments.show',$invoice->shipment_id) }}">{{ $invoice->shipment->reference }}</a></div>
<div class="record"><strong>Goods subtotal</strong><span>{{ $invoice->currency }} {{ $invoice->subtotal }}</span></div>
<div class="record"><strong>Discount</strong><span>{{ $invoice->discount_amount }}</span></div>
<div class="record"><strong>Tax amount</strong><span>{{ $invoice->tax_amount }}</span></div>
<div class="record"><strong>Freight</strong><span>{{ $invoice->freight_amount }}</span></div>
<div class="record"><strong>Insurance</strong><span>{{ $invoice->insurance_amount }}</span></div>
<div class="record"><strong>Commercial invoice total</strong><strong>{{ $invoice->currency }} {{ $invoice->grand_total }}</strong></div>
</section>
<div class="notice"><span>●</span><div>
@if($invoice->posting_status==='credited')
<strong>This invoice has been fully credited by an approved reversing journal</strong>
<p>The original commercial invoice is retained for the audit trail. This does not mean money was returned or cargo was recovered.</p>
@elseif($invoice->posting_status==='posted')
<strong>Commercial invoice recognised by reviewed journal posting</strong>
<p>The financial recognition workflow has posted an approved journal. This is not evidence of customer payment, bank reconciliation, tax compliance or receipt settlement.</p>
@else
<strong>Financial posting pending</strong>
<p>This commercial invoice is not yet recognised in the ledger. Record shipment dispatch and submit the transaction for independent finance approval.</p>
@endif
@if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'finance.view'))<p><a class="action-link" href="{{ route('finance.index') }}">Finance and trial balance</a></p>@endif
@if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'finance.credits.view'))
@if($invoice->creditNote)
<p><a class="action-link" href="{{ route('finance.credits.show',$invoice->creditNote->id) }}">View credit correction {{ $invoice->creditNote->reference }}</a></p>
@elseif($invoice->posting_status==='posted')
<p><a class="action-link" href="{{ route('finance.credits.index') }}">Review full-credit correction eligibility</a></p>
@endif
@endif
<p>Partial credits, replacement invoices, refunds and statutory corrections are not yet supported.</p>
</div></div>
@endsection