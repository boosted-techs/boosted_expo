@extends('layouts.console')
@section('title', 'Stock Batches')
@section('content')
<div class="page-intro"><div><span class="eyebrow">WAREHOUSE</span><h1>Stock batches</h1><p>Accepted produce recorded at receiving, with traceability back to origin.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('receiving.index') }}">Goods receiving</a><a class="secondary-button" href="{{ route('purchases.index') }}">Purchases</a></div>
<section class="section-card"><h2>Available unpacked stock</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Batch</th><th>Product</th><th>Available quantity</th><th>Origin reference</th><th>Received</th><th>QR information</th></tr></thead><tbody>
@forelse($batches as $batch)
<tr><td><strong>{{ $batch->batch_code }}</strong></td><td>{{ $batch->product->name }}</td><td>{{ $balanceByBatch[$batch->id] ?? '0' }} {{ $batch->product->base_unit }}</td><td>{{ $batch->source_reference ?: '—' }}</td><td>{{ $batch->received_on ?: '—' }}</td>
<td>@if($canPublish)
<form method="POST" action="{{ route('stock.qr.visibility',$batch->id) }}">@csrf @method('PUT')
<input type="hidden" name="public_enabled" value="{{ $publicByBatch->has($batch->id) ? 0 : 1 }}">
<button class="text-button">{{ $publicByBatch->has($batch->id) ? 'Hide public QR' : 'Enable public QR' }}</button>
</form>
@else{{ $publicByBatch->has($batch->id) ? 'Public' : 'Hidden' }}@endif</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No stock batches yet.</td></tr>@endforelse
</tbody></table></div>{{ $batches->links() }}</section>
<p class="form-hint">These balances include accepted goods received, minus quantities packed into cartons and quantities recorded as sorting or grading losses. Other adjustments and shipment allocations will be added in a later phase.</p>
@endsection