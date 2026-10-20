@extends('layouts.console')
@section('title','Sales Quotation')
@section('content')
<div class="page-intro"><div><span class="eyebrow">CUSTOMER QUOTATION</span><h1>{{ $quote->reference }}</h1><p>{{ $quote->salesRequest->customer->name }} · Revision {{ $quote->revision }} · {{ ucfirst($quote->status) }}</p></div><span class="tiny-pill">{{ strtoupper($quote->status) }}</span></div>
<div class="form-actions" style="margin-bottom:18px">
<a class="secondary-button" href="{{ route('sales-requests.show',$quote->sales_request_id) }}">Customer request</a>
@if($quote->status!=='draft')
<a class="secondary-button" href="{{ route('sales-quotes.print',$quote->id) }}" target="_blank" rel="noopener noreferrer">Printable quotation</a>
<a class="secondary-button" href="{{ route('sales-quotes.pdf',$quote->id) }}">Download PDF</a>
@endif
</div>
@if($quote->status==='draft' && $canManage)
<section class="section-card"><h2>Quotation pricing & terms</h2>
<p class="muted">Set a quantity and price for each product. The system calculates line totals, discount and tax. Confirm the applicable rate with your accountant before sharing.</p>
<form method="POST" action="{{ route('sales-quotes.update',$quote->id) }}" class="form-stack">@csrf @method('PUT')
<div class="form-columns"><label class="field"><span>Quotation currency</span><input name="currency" required minlength="3" maxlength="3" value="{{ old('currency',$quote->currency) }}"></label>
<label class="field"><span>Valid until</span><input type="date" name="valid_until" value="{{ old('valid_until',$quote->valid_until?->format('Y-m-d')) }}"></label></div>
<label class="field"><span>Agreed delivery term</span><select name="incoterm"><option value="">Not agreed</option>
@foreach(['EXW','FCA','CPT','CIP','DAP','DPU','DDP'] as $term)<option value="{{ $term }}" @selected(old('incoterm',$quote->incoterm)===$term)>{{ $term }}</option>@endforeach
</select></label>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Requested</th><th>Offered quantity</th><th>Price per unit</th></tr></thead><tbody>
@foreach($quote->lines as $i=>$line)
<tr><td><strong>{{ $line->product->name }}</strong><small>{{ $line->product->sku }} · {{ $line->product->base_unit }}</small></td><td>{{ $quote->salesRequest->items->firstWhere('product_id',$line->product_id)?->quantity }}</td>
<td><input type="hidden" name="items[{{ $i }}][product_id]" value="{{ $line->product_id }}"><input class="compact-input" type="number" name="items[{{ $i }}][quantity]" value="{{ old('items.'.$i.'.quantity',$line->quantity) }}" min="0.001" step="0.001" required></td>
<td><input class="compact-input" type="number" name="items[{{ $i }}][unit_price]" value="{{ old('items.'.$i.'.unit_price',$line->unit_price) }}" min="0.0001" step="0.0001" required></td></tr>
@endforeach
</tbody></table></div>
<div class="form-columns"><label class="field"><span>Discount (%)</span><input type="number" name="discount_percent" min="0" max="100" step=".01" value="{{ old('discount_percent',$quote->discount_percent) }}" required></label>
<label class="field"><span>Tax rate (%)</span><input type="number" name="tax_percent" min="0" max="100" step=".01" value="{{ old('tax_percent',$quote->tax_percent) }}" required></label></div>
<label class="field"><span>Terms and notes</span><textarea name="notes" rows="3" maxlength="2000">{{ old('notes',$quote->notes) }}</textarea></label>
<div class="form-actions"><button class="primary-button">Save quotation draft</button></div>
</form></section>
@endif
<section class="section-card"><h2>Quotation summary</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Qty</th><th>Unit price ({{ $quote->currency }})</th><th>Amount ({{ $quote->currency }})</th></tr></thead><tbody>
@foreach($quote->lines as $line)<tr><td>{{ $line->product_name?:$line->product->name }}</td><td>{{ $line->quantity }} {{ $line->unit?:$line->product->base_unit }}</td><td>{{ $line->unit_price }}</td><td>{{ $line->line_total }}</td></tr>@endforeach
</tbody></table></div>
<div class="record"><strong>Subtotal</strong><span>{{ $quote->currency }} {{ $quote->subtotal }}</span></div>
<div class="record"><strong>Discount ({{ $quote->discount_percent }}%)</strong><span>{{ $quote->discount_amount }}</span></div>
<div class="record"><strong>Tax ({{ $quote->tax_percent }}%)</strong><span>{{ $quote->tax_amount }}</span></div>
<div class="record"><strong>Quoted total</strong><span><strong>{{ $quote->currency }} {{ $quote->grand_total }}</strong></span></div>
@if($quote->incoterm)<div class="record"><strong>Delivery term</strong><span>{{ $quote->incoterm }}</span></div>@endif
<div class="record"><strong>Valid until</strong><span>{{ $quote->valid_until?->format('d M Y') ?: 'Not specified' }}</span></div>
@if($quote->notes)<p class="form-hint" style="margin-top:15px">{{ $quote->notes }}</p>@endif
</section>
@if($canManage && $quote->status==='draft')
<section class="section-card"><h2>Issue for sharing</h2><p class="muted">Issuing locks these prices and creates the printable version. Download the PDF and share it with the buyer yourself; email is not sent automatically.</p>
<form method="POST" action="{{ route('sales-quotes.issue',$quote->id) }}">@csrf<button class="primary-button">Issue quotation</button></form></section>
@endif
@if($canConfirm && $quote->status==='issued' && !$quote->salesRequest->order)
<section class="section-card form-narrow"><h2>Confirm customer order</h2>
<p class="muted">Only confirm after the buyer accepts this exact quotation. Record the buyer's purchase-order reference, email reference or other written confirmation. This is a staff-recorded confirmation, not a digital signature.</p>
<form method="POST" action="{{ route('sales-quotes.confirm',$quote->id) }}" class="form-stack">@csrf
<label class="field"><span>Buyer confirmation reference</span><input name="buyer_confirmation" minlength="5" maxlength="160" required placeholder="e.g. Buyer PO-934 or email dated 20 Oct"></label>
<button class="primary-button">Confirm sales order</button></form>
</section>
@endif
@if($quote->salesRequest->order)<div class="notice"><span>●</span><div><strong>Sales order confirmed</strong><p><a href="{{ route('sales-orders.show',$quote->salesRequest->order->id) }}" class="action-link">{{ $quote->salesRequest->order->reference }}</a> · No payment has been recorded.</p></div></div>@endif
@endsection