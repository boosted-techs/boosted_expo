@extends('layouts.console')
@section('title', 'Edit Product')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CATALOGUE</span><h1>Edit product</h1><p>Update product details for your company.</p></div></div>
<section class="section-card form-narrow">
<form method="POST" action="{{ route('products.update', $product->id) }}" class="form-stack">@csrf @method('PUT')
<div class="form-columns"><label class="field"><span>Product name</span><input name="name" value="{{ old('name',$product->name) }}" required maxlength="160"></label>
<label class="field"><span>Product code (SKU)</span><input name="sku" value="{{ old('sku',$product->sku) }}" required maxlength="80"></label></div>
<label class="field"><span>Unit</span><select name="base_unit">
@foreach(['kg','g','tonne','pcs','box','crate','bunch','sack','litre'] as $unit)<option value="{{ $unit }}" @selected(old('base_unit',$product->base_unit)===$unit)>{{ $unit }}</option>@endforeach
</select></label>
<label class="field"><span>Description</span><textarea name="description" rows="4" maxlength="3000">{{ old('description',$product->description) }}</textarea></label>
<input type="hidden" name="active" value="0"><label class="check-row"><input type="checkbox" name="active" value="1" @checked(old('active',$product->active))> Product is active</label>
<div class="form-actions"><button type="submit" class="primary-button">Save changes</button><a class="secondary-button" href="{{ route('products.index') }}">Cancel</a></div>
</form>
</section>
<section class="section-card form-narrow">
<h2>Product photographs</h2>
<p class="muted">Upload up to four images. The system creates an optimised photo and a thumbnail on Cloudflare R2.</p>
@if(app(\App\Services\ProductImages::class)->configured() && $product->images()->count() < 4)
<form method="POST" action="{{ route('products.images.store', $product->id) }}" enctype="multipart/form-data" class="form-stack">@csrf
<label class="field"><span>Upload image</span><input type="file" accept=".jpg,.jpeg,.png,.webp" name="photo" required><small>JPEG, PNG or WebP, up to 4 MB per image.</small></label>
<button class="secondary-button">Upload photograph</button>
</form>
@elseif(!app(\App\Services\ProductImages::class)->configured())
<p class="notice-message">Image storage is not connected yet. Ask your platform administrator to configure Cloudflare R2.</p>
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
</section>
<section class="section-card form-narrow">
<h2>Product QR & online page</h2>
<p class="muted">Scanning this QR code opens the product information page only while public access is enabled. Purchase costs and private company records are never shown.</p>
<div class="qr-panel">
<img src="{{ route('products.qr.png', $product->id) }}" alt="QR code for {{ $product->name }}" width="175" height="175">
<div>
  <strong>{{ $qr->public_enabled ? 'Public page enabled' : 'Public page hidden' }}</strong>
  <p class="form-hint">Product QR — {{ $product->sku }}</p>
  @if($qr->public_enabled)<a class="action-link" href="{{ route('qr.public', $qr->public_token) }}" target="_blank" rel="noopener noreferrer">View public page ↗</a>@else<p class="form-hint">Enable sharing to make this page available when scanned.</p>@endif
</div>
</div>
<form method="POST" action="{{ route('products.qr.update', $product->id) }}" class="form-actions">@csrf @method('PUT')
<input type="hidden" name="public_enabled" value="{{ $qr->public_enabled ? '0' : '1' }}">
<button type="submit" class="secondary-button">{{ $qr->public_enabled ? 'Hide public product page' : 'Make product page public' }}</button>
</form>
</section>
@endsection