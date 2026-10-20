@extends('layouts.console')
@section('title', 'Packed Cartons')
@section('content')
<div class="page-intro"><div><span class="eyebrow">WAREHOUSE OPERATIONS</span><h1>Packed cartons</h1><p>Identify every carton, its product, weights and source produce batches.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('packing.index') }}">Packing jobs</a><a class="secondary-button" href="{{ route('labels.index') }}">Label templates</a></div>
<section class="section-card">
<form method="GET" action="{{ route('cartons.index') }}" class="search-row"><label class="field grow"><span>Find a carton</span><input name="search" value="{{ $search }}" type="search" placeholder="Carton reference"></label><button class="secondary-button">Search</button></form>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Carton</th><th>Product</th><th>Grade</th><th>Net</th><th>Gross</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($cartons as $carton)<tr><td><strong>{{ $carton->carton_code }}</strong></td><td>{{ $carton->packingJob?->product?->name ?: 'Not linked to a packing job' }}</td><td>{{ $carton->grade ?: '—' }}</td><td>{{ $carton->net_weight_kg }} kg</td><td>{{ $carton->gross_weight_kg }} kg</td><td><span class="tiny-pill">{{ ucfirst($carton->status) }}</span></td><td><a class="action-link" href="{{ route('cartons.show',$carton->id) }}">Open</a></td></tr>
@empty<tr><td colspan="7" class="empty-msg">No packed cartons found.</td></tr>@endforelse
</tbody></table></div>{{ $cartons->links() }}
</section>
@endsection