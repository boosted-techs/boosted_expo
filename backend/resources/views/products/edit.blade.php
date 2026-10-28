@extends('layouts.console')
@section('title', $product->exists?'Product details':'Add product')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CATALOGUE</span><h1>{{ $product->exists ? $product->name : 'Add product' }}</h1><p>Update product details for your company.</p></div></div>
@if($product->exists)<nav class="activity-nav no-print" aria-label="Product activities"><a href="{{ route('products.edit',$product->id) }}">Details</a><a href="{{ route('products.edit',[$product->id,'activity'=>'photos']) }}">Photographs</a><a href="{{ route('products.edit',[$product->id,'activity'=>'qr']) }}">Product QR & online page</a><a href="{{ route('products.codes',$product->id) }}">Product codes</a></nav>@endif
@if($activity==='details')<section class="section-card">
<form method="POST" action="{{ $product->exists?route('products.update',$product->id):route('products.store') }}" class="form-stack" data-background-form>@csrf @if($product->exists)@method('PUT')@endif
<div class="form-columns"><label class="field"><span>Product name</span><input name="name" value="{{ old('name',$product->name) }}" required maxlength="160"></label>
<label class="field"><span>Product code (SKU)</span><input name="sku" value="{{ old('sku',$product->sku) }}" maxlength="80" placeholder="Leave blank to generate from the product name"></label></div>
<label class="field"><span>Unit</span><select name="base_unit">
@foreach(['kg','g','tonne','pcs','box','crate','bunch','sack','litre'] as $unit)<option value="{{ $unit }}" @selected(old('base_unit',$product->base_unit)===$unit)>{{ $unit }}</option>@endforeach
</select></label>
<label class="field"><span>Description</span><textarea name="description" rows="4" maxlength="3000">{{ old('description',$product->description) }}</textarea></label>
<label class="field"><span>Low-stock warning threshold ({{ $product->base_unit }})</span>
<input name="reorder_point" type="number" min="0" step="0.001" max="999999999" value="{{ old('reorder_point',$product->reorder_point) }}">
<small>Optional warning for total unpacked stock of this product. Does not automatically create a purchase.</small></label>
@if($product->exists)<input type="hidden" name="active" value="0"><label class="check-row"><input type="checkbox" name="active" value="1" @checked(old('active',$product->active))> Product is active</label>@endif
<div class="form-actions"><button type="submit" class="primary-button">Save changes</button><a class="secondary-button" href="{{ route('products.index') }}">Cancel</a></div>
</form>
</section>@endif
@if($activity==='photos' && $product->exists)<section class="section-card">
<h2>Product photographs</h2>
<p class="muted">Upload up to four product photos.</p>
@if(app(\App\Services\ProductImages::class)->configured() && $product->images()->count() < 4)
<form method="POST" action="{{ route('products.images.store', $product->id) }}" enctype="multipart/form-data" class="form-stack">@csrf
<label class="field"><span>Upload image</span><input type="file" accept=".jpg,.jpeg,.png,.webp" name="photo" required><small>JPEG, PNG or WebP, up to 4 MB per image.</small></label>
<button class="secondary-button">Upload photograph</button>
</form>
@elseif(!app(\App\Services\ProductImages::class)->configured())
<p class="notice-message">Photo uploads are unavailable. Contact your administrator.</p>
@else
<p class="form-hint">The maximum number of photographs has been reached.</p>
@endif
<div class="image-grid">
@foreach($product->images as $image)
<div class="image-tile">
<img src="{{ route('products.images.show', ['product'=>$product->id, 'image'=>$image->id, 'size'=>'thumb']) }}" alt="Photograph of {{ $product->name }}" loading="lazy">
<form method="POST" action="{{ route('products.images.destroy', ['product'=>$product->id, 'image'=>$image->id]) }}">@csrf @method('DELETE')<button class="text-button">Remove photograph</button></form>
</div>
@endforeach
</div>
</section>@endif
@if($activity==='qr' && $product->exists)<section class="section-card" id="product-qr">
<h2>Product QR & online page</h2>
<p class="muted">Scanning this QR code opens the product information page only while public access is enabled. Purchase costs and private company records are never shown.</p>
<div class="qr-panel" id="product-qr-print"><div><strong>{{ $product->name }}</strong><p>{{ $product->sku }}</p></div>
<img src="{{ route('products.qr.png', $product->id) }}" alt="QR code for {{ $product->name }}" width="175" height="175">
<div>
  <strong>{{ $qr->public_enabled ? 'Public page enabled' : 'Public page hidden' }}</strong>
  <p class="form-hint">Product QR · {{ $product->sku }}</p>
  @if($qr->public_enabled)<a class="action-link" href="{{ route('qr.public', $qr->public_token) }}" target="_blank" rel="noopener noreferrer">View public page ↗</a>@else<p class="form-hint">Enable sharing to make this page available when scanned.</p>@endif
</div>
</div>
<div class="form-actions no-print"><button type="button" class="secondary-button" data-print-target="#product-qr-print">Print QR</button><a class="secondary-button" download="{{ $product->sku }}-QR.png" href="{{ route('products.qr.png',$product->id) }}">Download QR</a></div>
<form method="POST" action="{{ route('products.qr.update', $product->id) }}" class="form-stack" data-background-form>@csrf @method('PUT')
<label class="field"><span>Page access</span><select name="access" data-native-select><option value="hidden" @selected(!$qr->public_enabled)>Hidden</option><option value="public" @selected($qr->public_enabled && !$qr->access_pin_hash)>Public · anyone with the QR</option><option value="pin" @selected($qr->public_enabled && $qr->access_pin_hash)>Protected · secret PIN required</option></select></label>
<label class="field"><span>New secret PIN (optional when keeping the current PIN)</span><input type="password" name="pin" minlength="4" maxlength="64" autocomplete="new-password" placeholder="Set or replace the secret PIN"></label><button type="submit" class="primary-button">Save QR access</button>
</form>
</section>@endif
@endsection