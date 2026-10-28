@extends('layouts.console')
@section('title','Stock Valuation and COGS')
@section('content')
<div class="page-intro"><div><span class="eyebrow">INVENTORY ACCOUNTING</span><h1>Stock valuation & cost of goods sold</h1><p>Trace dispatched cartons and sorting losses back to recorded purchase batches before moving their costs out of inventory.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.index') }}">Finance dashboard</a><a class="secondary-button" href="{{ route('finance.reviews') }}">Approval queue</a><a class="secondary-button" href="{{ route('finance.reports.index') }}">Financial reports</a></div>
<div class="notice"><span>●</span><div><strong>Never assume an unknown source cost is zero</strong><p>Only fully documented goods receipts and posted supplier bills qualify for valuation. A carton is valued once it has been dispatched; sorting and grading losses are costed separately. Approving and posting each valuation is a distinct action.</p></div></div>
@if($canPrepare)
<section class="section-card"><h2>Dispatched shipments awaiting COGS</h2>
<p class="muted">Prepare shipment produce costs, then request independent approval to post.</p>
@forelse($shipments as $shipment)
<div class="record"><div><strong>{{ $shipment->reference }}</strong><small>{{ $shipment->customer->name }} · {{ ucfirst($shipment->status) }}</small></div>
@if(in_array($shipment->id,$usedShipments,true))<span class="tiny-pill">Valuation exists</span>
@else<form method="POST" action="{{ route('finance.inventory.prepare',['kind'=>'cogs','id'=>$shipment->id]) }}">@csrf<button class="secondary-button">Prepare COGS</button></form>@endif</div>
@empty<p class="empty-msg">No dispatched shipments available.</p>@endforelse
</section>
<section class="section-card"><h2>Sorting and packing waste awaiting valuation</h2><p class="muted">Record the financial value of waste already removed from stock.</p>
@forelse($wastes as $waste)
<div class="record"><div><strong>{{ $waste->batch?->batch_code??'Unidentified batch' }}</strong><small>{{ $waste->quantity }} · {{ ucwords(str_replace('_',' ',$waste->reason)) }} · {{ $waste->created_at->format('d M Y') }}</small></div>
@if(in_array($waste->id,$usedWaste,true))<span class="tiny-pill">Valuation exists</span>
@else<form method="POST" action="{{ route('finance.inventory.prepare',['kind'=>'waste','id'=>$waste->id]) }}">@csrf<button class="secondary-button">Prepare waste write-off</button></form>@endif</div>
@empty<p class="empty-msg">No packing waste entries available.</p>@endforelse
</section>
@endif
<section class="section-card"><h2>Valuation history</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Document</th><th>Kind</th><th>Source</th><th>Cost ({{ $business->base_currency }})</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($reliefs as $r)
<tr><td><strong>{{ $r->reference }}</strong></td><td>{{ $r->kind==='cogs'?'Cost of goods sold':'Packing waste expense' }}</td>
<td>{{ $r->kind==='cogs'?'Shipment #'.$r->shipment_id:'Waste entry #'.$r->waste_entry_id }}</td><td>{{ $r->base_amount }}</td>
<td><span class="tiny-pill">{{ $r->posting_status==='posted'?'Posted':'Awaiting journal posting' }}</span></td>
<td><a class="action-link" href="{{ route('finance.inventory.show',$r->id) }}">Details</a></td></tr>
@empty<tr><td colspan="6" class="empty-msg">No inventory valuations prepared.</td></tr>@endforelse
</tbody></table></div>{{ $reliefs->links() }}</section>
@endsection