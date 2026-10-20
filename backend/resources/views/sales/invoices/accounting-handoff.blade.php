@extends('layouts.console')
@section('title','Invoice Finance Handoff')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ACCOUNTANT WORKSPACE</span><h1>Commercial invoice handoff</h1><p>Review issued export invoices awaiting your company's accounting and revenue-recognition policy.</p></div></div>
<div class="notice"><span>●</span><div><strong>No general-ledger posting has occurred</strong>
<p>These amounts are documentary values only. Revenue recognition, receivable posting, tax treatment, exchange rates and payment matching must be configured before financial posting. Do not treat these totals as recognised income.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('commercial-invoices.index') }}">Issued commercial invoices</a><a class="secondary-button" href="{{ route('sales-orders.index') }}">Sales orders</a></div>
<section class="section-card"><h2>Invoice values by currency</h2><p class="muted">Each currency is shown separately. Totals are not converted or combined.</p>
<div class="currency-summary">
@forelse($byCurrency as $currency=>$amount)<div class="currency-card"><span>{{ $currency }}</span><strong>{{ $amount }}</strong><small>Commercial invoice value · awaiting accounting policy</small></div>
@empty<p class="empty-msg">No invoices are awaiting accounting review.</p>@endforelse
</div></section>
<section class="section-card"><h2>Document handoffs</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Invoice</th><th>Buyer</th><th>Sales order</th><th>Shipment</th><th>Amount</th><th>Status</th></tr></thead><tbody>
@forelse($handoffs as $entry)
<tr><td><a class="action-link" href="{{ route('commercial-invoices.show',$entry->commercial_invoice_id) }}">{{ $entry->invoice->reference }}</a><small>{{ $entry->prepared_at?->format('d M Y H:i') }}</small></td>
<td>{{ $entry->invoice->customer->name }}</td>
<td>{{ $entry->invoice->order->reference }}</td>
<td>{{ $entry->invoice->shipment->reference }}</td>
<td><strong>{{ $entry->currency }} {{ $entry->commercial_amount }}</strong></td>
<td><span class="tiny-pill">Unposted</span></td></tr>
@empty<tr><td colspan="6" class="empty-msg">No commercial invoice handoffs yet.</td></tr>@endforelse
</tbody></table></div>{{ $handoffs->links() }}</section>
<p class="form-hint">This is a read-only financial handoff. Account mappings, debit/credit journal posting, tax invoices, currency translation, reversals and payment reconciliation will be implemented in a separate controlled phase.</p>
@endsection