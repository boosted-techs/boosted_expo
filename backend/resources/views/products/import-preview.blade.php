@extends('layouts.console')
@section('title', 'Review Product Import')
@section('content')
<div class="page-intro"><div><span class="eyebrow">IMPORT PREVIEW</span><h1>Review products</h1><p>Check the information before it changes your company's catalogue.</p></div></div>
<div class="notice"><span>●</span><div><strong>{{ $import->row_count }} unique products from {{ $parsed['sheet'] }}</strong><p>{{ $updates }} existing product codes will be updated. {{ $import->row_count - $updates }} will be new. {{ $parsed['duplicates'] }} repeated rows were skipped.</p></div></div>
<section class="section-card">
<div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>SKU</th><th>Unit</th><th>Description</th></tr></thead><tbody>
@foreach(array_slice($parsed['rows'],0,30) as $row)<tr><td><strong>{{ $row['name'] }}</strong></td><td>{{ $row['sku'] }}</td><td>{{ $row['base_unit'] }}</td><td>{{ \Illuminate\Support\Str::limit($row['description'], 60) }}</td></tr>@endforeach
</tbody></table></div>
@if($import->row_count>30)<p class="form-hint">Showing the first 30 items out of {{ $import->row_count }}. The full validated set will be applied.</p>@endif
<p class="form-hint">This preview expires after 30 minutes. Nothing has been imported yet.</p>
<form method="POST" action="{{ route('products.import.apply', $import->id) }}" class="form-actions">@csrf
<button class="primary-button" type="submit">Confirm import</button><a class="secondary-button" href="{{ route('products.import') }}">Cancel</a>
</form>
</section>
@endsection