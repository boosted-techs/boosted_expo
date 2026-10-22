@extends('layouts.console')
@section('title', 'Stock Batches')
@section('content')
<div class="page-intro"><div><span class="eyebrow">WAREHOUSE</span><h1>Stock batches</h1><p>Accepted produce recorded at receiving, with traceability back to origin.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('receiving.index') }}">Goods receiving</a><a class="secondary-button" href="{{ route('purchases.index') }}">Purchases</a></div>
@if(count($lowStock))
<section class="section-card"><h2>Low-stock warnings</h2>
<p class="muted">Warnings compare only quality-cleared unpacked stock across batches with your product reorder threshold. Produce under quality hold is excluded. Warnings do not place purchases.</p>
@foreach($lowStock as $entry)
<div class="record"><strong>{{ $entry['product']->name }}</strong>
<span>{{ $entry['available'] }} / {{ $entry['product']->reorder_point }} {{ $entry['product']->base_unit }} (available / warning level)</span></div>
@endforeach</section>
@endif
<section class="section-card"><h2>Unpacked stock and quality holds</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Batch</th><th>Product</th><th>Unpacked balance</th><th>Available to pack</th><th>Origin reference</th><th>Received</th><th>Quality status</th><th>QR information</th></tr></thead><tbody>
@forelse($batches as $batch)
<tr><td><strong>{{ $batch->batch_code }}</strong></td><td>{{ $batch->product->name }}</td><td>{{ $balanceByBatch[$batch->id] ?? '0' }} {{ $batch->product->base_unit }}</td>
<td><strong>{{ $batch->is_quarantined ? '0' : ($balanceByBatch[$batch->id] ?? '0') }}</strong> {{ $batch->product->base_unit }}</td>
<td>{{ $batch->source_reference ?: '—' }}</td><td>{{ $batch->received_on ?: '—' }}</td>
<td>
<strong>{{ $batch->is_quarantined?'QUALITY HOLD':'Quality cleared' }}</strong>
@if($batch->is_quarantined && $batch->quality_hold_reason)<small>{{ $batch->quality_hold_reason }}</small>@endif
@if(($canHold && !$batch->is_quarantined) || ($canRelease && $batch->is_quarantined))
<form method="POST" action="{{ route('stock.quality.change',[
'batch'=>$batch->id,'action'=>$batch->is_quarantined?'release':'hold']) }}" class="form-stack">@csrf
<input name="reason" required minlength="10" maxlength="500" placeholder="Quality inspection reason">
<button class="secondary-button">{{ $batch->is_quarantined?'Release quality hold':'Place on quality hold' }}</button>
</form>
@endif</td>
<td>@if($canPublish)
<form method="POST" action="{{ route('stock.qr.visibility',$batch->id) }}">@csrf @method('PUT')
<input type="hidden" name="public_enabled" value="{{ $publicByBatch->has($batch->id) ? 0 : 1 }}">
<button class="text-button">{{ $publicByBatch->has($batch->id) ? 'Hide public QR' : 'Enable public QR' }}</button>
</form>
@else{{ $publicByBatch->has($batch->id) ? 'Public' : 'Hidden' }}@endif</td></tr>
@empty<tr><td colspan="8" class="empty-msg">No stock batches yet.</td></tr>@endforelse
</tbody></table></div>{{ $batches->links() }}</section>
<p class="form-hint">Unpacked balances are physically recorded quantities after packing and grading losses. The available-to-pack column excludes all remaining quantity in quality-held batches. Carton packing consumes the exact source stock once; assigning already packed cartons to shipments does not subtract the same stock again.</p>
@endsection