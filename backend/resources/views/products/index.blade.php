@extends('layouts.console')
@section('title', 'Product Catalogue')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CATALOGUE</span><h1>Products</h1><p>Manage the agricultural produce your company buys, packs and exports.</p></div>@if($canManage)<a class="primary-button" data-remote-dialog href="{{ route('products.create') }}">Add product</a>@endif</div>
<div class="form-actions" style="margin-bottom:18px">
<a class="secondary-button" href="{{ route('products.export') }}">Export to Excel</a>
@if($canManage)<a class="secondary-button" href="{{ route('products.import') }}">Import from Excel</a><a class="secondary-button" href="{{ route('products.template') }}">Excel template</a>@endif
</div>
<div class="panel-stack">
<div data-refresh-notice hidden class="flash success no-print" role="status">Changes saved. <button type="button" class="secondary-button" data-refresh-directory>Refresh catalogue</button></div><section class="section-card" data-directory id="product-directory">
<form method="GET" action="{{ route('products.index') }}" class="search-row">
<label class="field grow"><span>Search by product or code</span><input name="search" type="search" value="{{ $search }}" placeholder="e.g. Fresh avocado or AVOC-001"></label>
<button class="secondary-button" type="submit">Search</button>
@if($search!=='')<a href="{{ route('products.index') }}" class="text-button">Clear</a>@endif
</form>
<div class="table-wrap"><table class="data-table"><thead><tr><th data-sort="name">Product</th><th>SKU</th><th data-sort="base_unit">Unit</th><th data-sort="active">Status</th><th>Actions</th></tr></thead><tbody>
@forelse($products as $product)
<tr><td><div class="product-cell">
@if($product->images->isNotEmpty())
<img class="small-thumb" src="{{ route('products.images.show', ['product'=>$product->id, 'image'=>$product->images->first()->id, 'size'=>'thumb']) }}" alt="" loading="lazy">
@endif
<div><strong><a data-remote-dialog href="{{ route('catalogue.statement',['product',$product->id]) }}">{{ $product->name }}</a></strong><small>{{ \Illuminate\Support\Str::limit($product->description, 95) }}</small></div></div></td><td>{{ $product->sku }}</td><td>{{ $product->base_unit }}</td><td><span class="tiny-pill">{{ $product->active ? 'Active' : 'Archived' }}</span></td>
<td><x-row-actions><a data-remote-dialog href="{{ route('catalogue.statement',['product',$product->id]) }}">Product statement</a>@if($canManage)<a data-remote-dialog href="{{ route('products.edit',$product->id) }}">Edit product</a><a data-remote-dialog href="{{ route('products.codes',$product->id) }}">Product codes</a><a data-remote-dialog href="{{ route('products.edit',[$product->id,'activity'=>'photos']) }}">Product photographs</a><a data-remote-dialog href="{{ route('products.edit',[$product->id,'activity'=>'qr']) }}">QR & online page</a><a data-remote-dialog href="{{ route('products.edit',[$product->id,'activity'=>'qr']) }}">Public page access</a><x-archive-action type="product" :record="$product" />@endif</x-row-actions></td>
</tr>
@empty<tr><td colspan="5" class="empty-msg">No products found. @if($canManage) Add your first product. @endif</td></tr>
@endforelse
</tbody></table></div>{{ $products->links() }}
</section>
</div>
@endsection