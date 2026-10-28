@extends('layouts.console')
@section('title','Inventory Valuation')
@section('content')
<div class="page-intro"><div><span class="eyebrow">COST SNAPSHOT</span><h1>{{ $relief->reference }}</h1><p>{{ $relief->kind==='cogs'?'Dispatched carton cost of goods sold':'Sorting and grading waste cost' }} · {{ $relief->currency }} {{ $relief->base_amount }}</p></div><span class="tiny-pill">{{ $relief->posting_status==='posted'?'POSTED':'UNPOSTED' }}</span></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.inventory.index') }}">All valuations</a>
<a class="secondary-button" href="{{ route('finance.reviews') }}">Posting approvals</a></div>
<div class="notice"><span>●</span><div><strong>Locked source-batch valuation</strong><p>Costs use billed purchase prices and approved exchange rates. Open shipment reports to review other costs.</p></div></div>
<section class="section-card"><h2>Cost allocation by source batch</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Batch</th><th>Purchase</th><th>Supplier bill</th><th>Quantity</th><th>Source FX rate</th><th>Base cost ({{ $relief->currency }})</th></tr></thead><tbody>
@foreach($relief->data_snapshot['lines'] as $line)<tr><td>{{ $line['batch_code'] }}</td><td>{{ $line['purchase_reference'] }}</td><td>{{ $line['supplier_bill_reference'] }}</td>
<td>{{ $line['quantity'] }} {{ $line['unit'] }}</td><td>{{ $line['original_currency'] }} @ {{ $line['rate_to_base'] }}</td><td>{{ $line['base_cost'] }}</td></tr>@endforeach
</tbody><tfoot><tr><td colspan="5"><strong>Total inventory relieved</strong></td><td><strong>{{ $relief->currency }} {{ $relief->base_amount }}</strong></td></tr></tfoot></table></div>
<div class="record"><strong>Purpose</strong><span>{{ $relief->kind==='cogs'?'Debit cost of goods sold / credit inventory':'Debit spoilage loss / credit inventory' }}</span></div>
<div class="record"><strong>Posting status</strong><span>{{ $relief->posting_status }}</span></div>
@if($relief->kind==='cogs')<p class="form-hint">Related shipment: <a class="action-link" href="{{ route('shipments.show',$relief->shipment_id) }}">Open shipment</a></p>@endif
</section>
@if($relief->posting_status==='not_posted' && app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'finance.reviews.request'))
<p class="form-hint">Open finance reviews, select this valuation and request independent approval.</p>
@endif
@endsection