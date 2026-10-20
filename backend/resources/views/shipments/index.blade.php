@extends('layouts.console')
@section('title','Export Shipments')
@section('content')
<div class="page-intro"><div><span class="eyebrow">EXPORT OPERATIONS</span><h1>Export shipments</h1><p>Plan flights, allocate packed cartons, prepare buyer packing lists and record dispatch.</p></div></div>
<div class="form-actions" style="margin-bottom:18px">
@if($canManage)<a class="primary-button" href="{{ route('shipments.create') }}">New shipment</a>@endif
<a class="secondary-button" href="{{ route('export-documents.index') }}">Export documents</a>
<a class="secondary-button" href="{{ route('document-templates.index') }}">Document templates</a>
<a class="secondary-button" href="{{ route('cartons.index') }}">Packed cartons</a>
</div>
<section class="section-card"><h2>Shipment register</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Shipment</th><th>Buyer</th><th>Route</th><th>Flight / AWB</th><th>Cartons</th><th>Status</th><th></th></tr></thead>
<tbody>@forelse($shipments as $shipment)
<tr><td><strong>{{ $shipment->reference }}</strong><small>{{ $shipment->created_at->format('d M Y') }}</small></td>
<td>{{ $shipment->customer->name }}</td>
<td>{{ $shipment->origin_airport ?: $shipment->origin_country }} → {{ $shipment->destination_airport ?: $shipment->destination_country }}</td>
<td><strong>{{ $shipment->flight_number ?: 'Pending' }}</strong><small>{{ $shipment->air_waybill ?: 'No AWB yet' }}</small></td>
<td>{{ $shipment->allocations_count }}</td><td><span class="tiny-pill">{{ ucfirst($shipment->status) }}</span></td>
<td><a class="action-link" href="{{ route('shipments.show',$shipment->id) }}">Open</a></td></tr>
@empty<tr><td colspan="7" class="empty-msg">No shipments planned yet. @if($canManage)Create your first shipment above.@endif</td></tr>@endforelse
</tbody></table></div>{{ $shipments->links() }}
</section>
<p class="form-hint">A shipment recorded as dispatched is a manual status entry, not confirmation from an airline or customs authority.</p>
@endsection