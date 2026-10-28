@extends('layouts.console')
@section('title','Shipment Contribution Estimate')
@section('content')
<div class="page-intro"><div><span class="eyebrow">FINANCIAL COST REVIEW</span><h1>Shipment contribution estimate</h1><p>{{ $shipment->reference }} · {{ $shipment->customer->name }} · Base currency {{ $report['base_currency'] }}</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('shipments.show',$shipment->id) }}">Shipment details</a>
<a class="secondary-button" href="{{ route('finance.index') }}">Finance dashboard</a><a class="secondary-button" href="{{ route('finance.documents') }}">Source costs</a></div>
<div class="notice"><span>●</span><div><strong>Management estimate — not statutory or audited profit</strong><p>Compare posted revenue with billed produce and shipment costs. This estimate excludes waste, packing materials, wages, overhead and other allocations; use financial reports for recognised profit.</p></div></div>
<section class="section-card"><h2>Estimated contribution in {{ $report['base_currency'] }}</h2>
<div class="record"><strong>Recognised invoice revenue</strong><span>{{ $report['revenue']??'Awaiting recognition' }}</span></div>
<div class="record"><strong>Known packed-produce acquisition cost</strong><span>{{ $report['acquisition_cost'] }}</span></div>
<div class="record"><strong>Posted direct shipment costs</strong><span>{{ $report['operating_cost'] }}</span></div>
<div class="record"><strong>Provisional contribution before remaining expenses</strong>
<strong>{{ $report['provisional_contribution']??'Insufficient validated data' }}</strong></div>
@if($report['missing']!==[])
<h3>Missing or incomplete inputs</h3>
@foreach($report['missing'] as $issue)<p class="form-hint">• {{ $issue }}</p>@endforeach
@endif
<p class="form-hint">No journal entries are created from this estimate; it is a read-only operational analysis.</p>
</section>
<section class="section-card"><h2>Cartons and traceable acquisition cost</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Carton</th><th>Product</th><th>Batch</th><th>Quantity</th><th>Source purchase</th><th>Known cost ({{ $report['base_currency'] }})</th></tr></thead><tbody>
@forelse($report['source_lines'] as $line)
<tr><td>{{ $line['carton'] }}</td><td>{{ $line['product'] }}</td><td>{{ $line['batch']??'Unrecorded' }}</td>
<td>{{ $line['quantity'] }} {{ $line['unit'] }}</td><td>{{ $line['purchase']??'Missing' }} @if($line['purchase_currency']) · {{ $line['purchase_currency'] }}@endif</td>
<td>{{ $line['base_cost']??'Pending source bill posting' }}</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No allocated cartons were found.</td></tr>@endforelse
</tbody></table></div>
</section>
<section class="section-card"><h2>Direct shipment expenses</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Expense</th><th>Category</th><th>Description</th><th>Recorded payment</th><th>Known base amount</th><th>Status</th></tr></thead><tbody>
@forelse($report['expenses'] as $expense)
<tr><td>{{ $expense['reference'] }}</td><td>{{ ucwords(str_replace('_',' ',$expense['category'])) }}</td><td>{{ $expense['description'] }}</td>
<td>{{ $expense['currency'] }} {{ $expense['amount'] }}</td><td>{{ $expense['base_amount']??'Not posted' }}</td><td>{{ ucfirst($expense['status']) }}</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No direct costs have been recorded for this shipment.</td></tr>@endforelse
</tbody></table></div>
</section>
@endsection