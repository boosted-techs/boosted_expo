@extends('layouts.console')
@section('title', 'Product Catalogue')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CATALOGUE</span><h1>Products</h1><p>Manage the agricultural produce your company buys, packs and exports.</p></div></div>
<div class="form-actions" style="margin-bottom:18px">
<a class="secondary-button" href="{{ route('products.export') }}">Export to Excel</a>
@if($canManage)<a class="secondary-button" href="{{ route('products.import') }}">Import from Excel</a><a class="secondary-button" href="{{ route('products.template') }}">Excel template</a>@endif
</div>
<div class="panel-stack">
<section class="section-card">
<form method="GET" action="{{ route('products.index') }}" class="search-row">
<label class="field grow"><span>Search by product or code</span><input name="search" type="search" value="{{ $search }}" placeholder="e.g. Fresh avocado or AVOC-001"></label>
<button class="secondary-button" type="submit">Search</button>
@if($search!=='')<a href="{{ route('products.index') }}" class="text-button">Clear</a>@endif
</form>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>SKU</th><th>Unit</th><th>Status</th>@if($canManage)<th></th>@endif</tr></thead><tbody>
@forelse($products as $product)
<tr><td><div class="product-cell">
@if($product->images->isNotEmpty())
<img class="small-thumb" src="{{ route('products.images.show', ['product'=>$product->id, 'image'=>$product->images->first()->id, 'size'=>'thumb']) }}" alt="" loading="lazy">
@endif
<div><strong>{{ $product->name }}</strong><small>{{ \Illuminate\Support\Str::limit($product->description, 95) }}</small></div></div></td><td>{{ $product->sku }}</td><td>{{ $product->base_unit }}</td><td><span class="tiny-pill">{{ $product->active ? 'Active' : 'Archived' }}</span></td>
@if($canManage)<td><a class="action-link" href="{{ route('products.edit', $product->id) }}">Edit</a></td>@endif
</tr>
@empty<tr><td colspan="{{ $canManage ? 5 : 4 }}" class="empty-msg">No products found. @if($canManage) Add your first product below. @endif</td></tr>
@endforelse
</tbody></table></div>{{ $products->links() }}
</section>
@if($canManage)
<section class="section-card form-narrow"><h2>Add a product</h2>
<form method="POST" action="{{ route('products.store') }}" class="form-stack">@csrf
<div class="form-columns"><label class="field"><span>Product name</span><input name="name" value="{{ old('name') }}" maxlength="160" required></label><label class="field"><span>Product code (SKU)</span><input name="sku" value="{{ old('sku') }}" maxlength="80" placeholder="MAT-001" required></label></div>
<label class="field"><span>Unit</span><select name="base_unit">
@foreach(['kg','g','tonne','pcs','box','crate','bunch','sack','litre'] as $unit)<option value="{{ $unit }}" @selected(old('base_unit','kg')===$unit)>{{ $unit }}</option>@endforeach
</select></label>
<label class="field"><span>Description (optional)</span><textarea name="description" rows="3" maxlength="3000">{{ old('description') }}</textarea></label>
<button class="primary-button" type="submit">Add product</button></form>
<p class="form-hint">Product photographs and Cloudflare R2 media uploads are being prepared in a separate integration pass.</p>
</section>
@endif
</div>
@endsection