@props(['products','limit'=>20])
<div data-request-items data-limit="{{ $limit }}">
<div class="table-wrap"><table class="data-table request-items-table" data-table-tools="off"><thead><tr><th class="row-number">#</th><th>Product</th><th>Quantity</th><th>Action</th></tr></thead><tbody>
@foreach(old('items',[['product_id'=>'','quantity'=>'']]) as $i=>$line)
<tr data-request-line><td class="row-number">{{ $loop->iteration }}</td><td><select name="items[{{ $i }}][product_id]" aria-label="Product {{ $loop->iteration }}" required><option value="">Search products…</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(($line['product_id']??'')==$product->id)>{{ $product->name }} · {{ $product->base_unit }}</option>@endforeach</select></td><td><input aria-label="Quantity {{ $loop->iteration }}" name="items[{{ $i }}][quantity]" type="number" min="0.001" step="0.001" placeholder="0.000" value="{{ $line['quantity']??'' }}" required></td><td><button type="button" class="text-button" data-remove-item aria-label="Remove product">Remove</button></td></tr>
@endforeach
</tbody></table></div>
<button type="button" class="secondary-button" data-add-item>Add product</button>
</div>