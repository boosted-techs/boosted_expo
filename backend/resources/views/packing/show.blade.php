@extends('layouts.console')
@section('title', 'Packing Job')
@section('content')
<div class="page-intro"><div><span class="eyebrow">PACKING RECORD</span><h1>{{ $job->reference }}</h1><p>{{ $job->product->name }} · {{ $job->product->base_unit }} · {{ ucfirst(str_replace('_',' ',$job->status)) }}</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('packing.index') }}">All packing jobs</a><a class="secondary-button" href="{{ route('cartons.index') }}">Packed cartons</a></div>
<section class="section-card"><h2>Available source batches</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Batch</th><th>Origin</th><th>Available to pack</th></tr></thead><tbody>
@forelse($batches as $entry)<tr><td><strong>{{ $entry['batch']->batch_code }}</strong></td><td>{{ $entry['batch']->source_reference ?: 'Not specified' }}</td><td>{{ $entry['available'] }} {{ $job->product->base_unit }} @if($entry['batch']->is_quarantined)<strong>· QUALITY HOLD</strong>@endif</td></tr>
@empty<tr><td colspan="3" class="empty-msg">No produce has been received for this product yet. <a class="action-link" href="{{ route('receiving.index') }}">View receiving</a>.</td></tr>@endforelse
</tbody></table></div>
<p class="form-hint">Only accepted goods enter available stock. Packing and sorting losses deduct stock from their exact source batches.</p>
</section>
@if($canManage && in_array($job->status,['open','in_progress'],true))
<div class="split-panels">
<section class="section-card"><h2>Pack a carton</h2>
<p class="muted">Choose one or more stock batches for the same product. You can mix batches when needed; each carton retains full traceability.</p>
<form method="POST" action="{{ route('packing.pack',$job->id) }}" class="form-stack">@csrf
<input type="hidden" name="packing_key" value="{{ $cartonKey }}">
<div id="batch-lines">
<div class="batch-line form-columns">
<label class="field"><span>Source batch</span><select name="contents[0][batch_id]" required><option value="">Select batch</option>@foreach($batches as $entry)@if(!$entry['batch']->is_quarantined && \Brick\Math\BigDecimal::of($entry['available'])->isGreaterThan(0))<option value="{{ $entry['batch']->id }}">{{ $entry['batch']->batch_code }} · {{ $entry['available'] }} available</option>@endif @endforeach</select></label>
<label class="field"><span>Quantity ({{ $job->product->base_unit }})</span><input name="contents[0][quantity]" type="number" min="0.001" step="0.001" required></label>
</div></div>
<button type="button" class="secondary-button" id="more-batch">Add another source batch</button>
<div class="form-columns">
<label class="field"><span>Net weight (kg)</span><input type="number" name="net_weight_kg" min="0.001" step="0.001" required></label>
<label class="field"><span>Gross weight (kg)</span><input type="number" name="gross_weight_kg" min="0.001" step="0.001" required></label>
</div>
<p class="form-hint">For products measured in kilograms, the total batch quantity must equal the net weight. Gross includes the packaging weight.</p>
<div class="form-columns"><label class="field"><span>Quality grade</span><input name="grade" maxlength="50" placeholder="e.g. Grade A"></label><label class="field"><span>Packaging</span><input name="packaging_type" maxlength="60" value="{{ $job->packing_type }}" placeholder="e.g. 15 kg carton"></label></div>
<button class="primary-button">Complete & save carton</button></form>
<template id="batch-line-template">
<div class="batch-line form-columns">
<label class="field"><span>Source batch</span><select name="contents[__INDEX__][batch_id]" required><option value="">Select batch</option>@foreach($batches as $entry)@if(!$entry['batch']->is_quarantined && \Brick\Math\BigDecimal::of($entry['available'])->isGreaterThan(0))<option value="{{ $entry['batch']->id }}">{{ $entry['batch']->batch_code }}</option>@endif @endforeach</select></label>
<label class="field"><span>Quantity</span><input name="contents[__INDEX__][quantity]" type="number" min="0.001" step="0.001" required></label>
<button type="button" class="text-button remove-batch">Remove batch</button>
</div></template>
</section>
<section class="section-card"><h2>Record sorting or grading loss</h2><p class="muted">Record produce discarded during sorting, trimming, grading or packing. These amounts cannot later be packed.</p>
<form method="POST" action="{{ route('packing.loss',$job->id) }}" class="form-stack">@csrf
<input type="hidden" name="idempotency_key" value="{{ $wasteKey }}">
<label class="field"><span>Source batch</span><select name="batch_id" required><option value="">Select batch</option>@foreach($batches as $entry)@if(\Brick\Math\BigDecimal::of($entry['available'])->isGreaterThan(0))<option value="{{ $entry['batch']->id }}">{{ $entry['batch']->batch_code }}</option>@endif @endforeach</select></label>
<label class="field"><span>Quantity lost ({{ $job->product->base_unit }})</span><input name="quantity" type="number" min="0.001" step="0.001" required></label>
<label class="field"><span>Reason</span><select name="reason" required><option value="sorting_reject">Rejected during sorting</option><option value="spoiled">Spoiled produce</option><option value="damaged">Damaged produce</option><option value="trim_loss">Trimming / cleaning loss</option><option value="other">Other loss</option></select></label>
<label class="field"><span>Notes (optional)</span><textarea name="notes" rows="2" maxlength="2000"></textarea></label>
<button class="secondary-button">Record loss</button></form>
</section>
</div>
@endif
<section class="section-card"><div class="form-actions" style="justify-content:space-between"><h2 style="margin:0">Packed cartons ({{ $job->cartons->count() }})</h2></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Carton</th><th>Grade</th><th>Net weight</th><th>Gross weight</th><th>Source batches</th><th></th></tr></thead><tbody>
@forelse($job->cartons as $carton)
<tr><td><strong>{{ $carton->carton_code }}</strong></td><td>{{ $carton->grade ?: '—' }}</td><td>{{ $carton->net_weight_kg }} kg</td><td>{{ $carton->gross_weight_kg }} kg</td><td>{{ $carton->contents->pluck('batch.batch_code')->implode(', ') }}</td><td><a class="action-link" href="{{ route('cartons.show',$carton->id) }}">View & label</a></td></tr>
@empty<tr><td colspan="6" class="empty-msg">No cartons packed yet.</td></tr>@endforelse
</tbody></table></div></section>
<section class="section-card"><h2>Sorting and grading losses</h2>
@forelse($job->losses as $loss)<div class="record"><div><strong>{{ $loss->batch->batch_code }} · {{ ucwords(str_replace('_',' ',$loss->reason)) }}</strong><small>{{ $loss->notes }}</small></div><span>{{ $loss->quantity }} {{ $job->product->base_unit }}</span></div>
@empty<p class="empty-msg">No sorting losses recorded.</p>@endforelse
</section>
@if($canManage && in_array($job->status,['open','in_progress'],true))
<section class="section-card"><h2>Finish packing</h2><p class="muted">Completing the job prevents further carton and sorting entries. Check all weights and losses first.</p>
<form method="POST" action="{{ route('packing.complete',$job->id) }}" data-confirm="Complete and lock this packing job?">@csrf<button class="primary-button">Complete packing job</button></form></section>
@endif
@if($job->status==='completed')<div class="notice"><span>●</span><div><strong>Packing completed</strong><p>Recorded cartons remain available for the future export-allocation workflow. No shipment or payment has been created.</p></div></div>@endif
<script src="/assets/packing-ui.js" defer></script>
@endsection