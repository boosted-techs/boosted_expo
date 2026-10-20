@extends('layouts.console')
@section('title', 'Packing Jobs')
@section('content')
<div class="page-intro"><div><span class="eyebrow">WAREHOUSE OPERATIONS</span><h1>Packing jobs</h1><p>Grade produce, prepare cartons and reconcile what remains in stock.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('stock.index') }}">Unpacked stock</a><a class="secondary-button" href="{{ route('cartons.index') }}">Packed cartons</a><a class="secondary-button" href="{{ route('labels.index') }}">Sticker templates</a></div>
<section class="section-card"><h2>Work in progress</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Job</th><th>Product</th><th>Cartons</th><th>Sorting records</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($jobs as $job)
<tr><td><strong>{{ $job->reference }}</strong><small>{{ $job->planned_on?->format('d M Y') ?: $job->created_at->format('d M Y') }}</small></td><td>{{ $job->product->name }}</td><td>{{ $job->cartons_count }} {{ $job->target_cartons ? ' / '.$job->target_cartons : '' }}</td><td>{{ $job->losses_count }}</td><td><span class="tiny-pill">{{ ucfirst(str_replace('_',' ',$job->status)) }}</span></td><td><a class="action-link" href="{{ route('packing.show',$job->id) }}">Open</a></td></tr>
@empty<tr><td colspan="6" class="empty-msg">No packing jobs yet.</td></tr>@endforelse
</tbody></table></div>{{ $jobs->links() }}</section>
@if($canManage)
<section class="section-card form-narrow"><h2>Start a packing job</h2>
<form method="POST" action="{{ route('packing.store') }}" class="form-stack">@csrf
<label class="field"><span>Product</span><select name="product_id" required><option value="">Choose produce</option>@foreach($products as $p)<option value="{{ $p->id }}" @selected(old('product_id')==$p->id)>{{ $p->name }} ({{ $p->base_unit }})</option>@endforeach</select></label>
<div class="form-columns"><label class="field"><span>Planned packing date</span><input name="planned_on" type="date" value="{{ old('planned_on') }}"></label><label class="field"><span>Target number of cartons (optional)</span><input name="target_cartons" type="number" min="1" max="1000000" value="{{ old('target_cartons') }}"></label></div>
<label class="field"><span>Packaging type (optional)</span><input name="packing_type" maxlength="60" value="{{ old('packing_type') }}" placeholder="e.g. Export cartons"></label>
<label class="field"><span>Instructions (optional)</span><textarea name="notes" rows="3" maxlength="2000">{{ old('notes') }}</textarea></label>
<button class="primary-button">Start packing job</button></form>
</section>
@endif
@endsection