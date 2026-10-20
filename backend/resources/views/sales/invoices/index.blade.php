@extends('layouts.console')
@section('title','Commercial Invoices')
@section('content')
<div class="page-intro"><div><span class="eyebrow">EXPORT SALES</span><h1>Commercial invoices</h1><p>Issued sale documents based on cartons allocated to confirmed customer orders.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('sales-orders.index') }}">Confirmed orders</a><a class="secondary-button" href="{{ route('shipments.index') }}">Shipments</a></div>
<section class="section-card"><h2>Issued invoices</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Invoice</th><th>Buyer</th><th>Shipment</th><th>Amount</th><th>Ledger status</th><th></th></tr></thead><tbody>
@forelse($invoices as $invoice)<tr><td><strong>{{ $invoice->reference }}</strong><small>{{ $invoice->issued_at?->format('d M Y') }}</small></td><td>{{ $invoice->customer->name }}</td><td><a href="{{ route('shipments.show',$invoice->shipment_id) }}" class="action-link">{{ $invoice->shipment->reference }}</a></td><td>{{ $invoice->currency }} {{ $invoice->grand_total }}</td><td><span class="tiny-pill">{{ $invoice->posting_status==='posted'?'Posted':'Not posted' }}</span></td><td><a class="action-link" href="{{ route('commercial-invoices.show',$invoice->id) }}">View</a></td></tr>
@empty<tr><td colspan="6" class="empty-msg">No commercial invoices yet. Link a shipment to a confirmed order and mark it ready first.</td></tr>@endforelse
</tbody></table></div>{{ $invoices->links() }}</section>
<p class="form-hint">Each shipment receives one immutable commercial invoice. Corrections and credit notes require a future controlled amendment workflow.</p>
@endsection