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
<p class="muted">Enter quantities, prices, discount and tax. Check the tax rate before sharing.</p>
<form method="POST" action="{{ route('sales-quotes.update',$quote->id) }}" class="form-stack">@csrf @method('PUT')
<div class="form-columns"><label class="field"><span>Quotation currency</span><x-currency-select :value="old('currency', $quote->currency)" /></label>
<label class="field"><span>Valid until</span><input type="date" name="valid_until" value="{{ old('valid_until',$quote->valid_until?->format('Y-m-d')) }}"></label></div>
<label class="field"><span>Reason for agreed change from the buyer default currency (when different)</span><input name="currency_override_reason" maxlength="250" value="{{ old('currency_override_reason',$quote->currency_override_reason) }}"></label>
<label class="field"><span>Internal pricing rate ({{ request()->attributes->get('activeBusiness')->base_currency }} per 1 foreign unit)</span><select name="pricing_exchange_rate_id"><option value="">Base currency only</option>@foreach($rates as $rate)<option value="{{ $rate->id }}" @selected(old('pricing_exchange_rate_id',$quote->pricing_exchange_rate_id)==$rate->id)>{{ $rate->currency }} · {{ $rate->rate_to_base }} · effective {{ $rate->effective_on->format('d M Y') }} · revision {{ $rate->revision }} · {{ $rate->source_note }}</option>@endforeach</select></label>
<label class="field"><span>Agreed payment days after invoice (blank = not agreed)</span><input name="payment_terms_days" type="number" min="0" max="365" value="{{ old('payment_terms_days',$quote->payment_terms_days) }}"></label>
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
@if($quote->pricing_snapshot)<section class="section-card"><h2>Internal pricing estimate</h2><p>{{ $quote->pricing_snapshot['base_currency'] }} {{ $quote->pricing_snapshot['base_grand_total'] }} · 1 {{ $quote->currency }} = {{ $quote->pricing_snapshot['rate_to_base'] }} {{ $quote->pricing_snapshot['base_currency'] }} · priced {{ $quote->pricing_snapshot['pricing_on'] }}</p><p class="form-hint">Frozen quotation assumption. The invoice and settlement use their own reviewed rates.</p></section>@endif
@if(session('quotation_url'))<section class="section-card"><h2>Private buyer link</h2><p>Share with the intended buyer. It is shown only on this response.</p><input aria-label="Private quotation link" readonly value="{{ session('quotation_url') }}" style="width:100%"></section>@endif
@if($canManage && $quote->status==='issued')<section class="section-card form-narrow"><h2>Share with buyer</h2><form method="POST" action="{{ route('sales-quotes.buyer-link',$quote->id) }}" class="form-stack">@csrf<label class="field"><span>Intended recipient email</span><input type="email" name="recipient_email" required value="{{ $quote->salesRequest->customer->email }}"></label><button class="primary-button">Create private buyer link</button></form></section>@endif
@if($canManage && $accesses->isNotEmpty())<section class="section-card"><h2>Buyer link history</h2>@foreach($accesses as $access)<p>{{ $access->recipient_email }} · expires {{ $access->expires_at->format('d M Y') }} · {{ $access->decision??($access->revoked_at?'withdrawn':'awaiting decision') }} · {{ $access->buyer_name }} {{ $access->buyer_reference }}</p>@if(!$access->revoked_at)<form method="POST" action="{{ route('sales-quotes.buyer-revoke',$access->id) }}">@csrf<button class="text-button">Withdraw link</button></form>@endif
@endforeach</section>@endif
@if($canManage && $quote->status==='draft')
<section class="section-card"><h2>Issue for sharing</h2><p class="muted">Issuing locks these prices and creates the printable version. Create a private buyer link after issuing, or download the PDF to share.</p>
<form method="POST" action="{{ route('sales-quotes.issue',$quote->id) }}">@csrf<button class="primary-button">Issue quotation</button></form></section>
@endif
@if($canConfirm && $quote->status==='issued' && !$quote->salesRequest->order)
<section class="section-card form-narrow"><h2>Confirm customer order</h2>
<p class="muted">Confirm after buyer acceptance. Enter their purchase order or written confirmation reference.</p>
<form method="POST" action="{{ route('sales-quotes.confirm',$quote->id) }}" class="form-stack">@csrf
<label class="field"><span>Buyer confirmation reference</span><input name="buyer_confirmation" minlength="5" maxlength="160" required placeholder="e.g. Buyer PO-934 or email dated 20 Oct"></label>
<button class="primary-button">Confirm sales order</button></form>
</section>
@endif
@if($quote->salesRequest->order)<div class="notice"><span>●</span><div><strong>Sales order confirmed</strong><p><a href="{{ route('sales-orders.show',$quote->salesRequest->order->id) }}" class="action-link">{{ $quote->salesRequest->order->reference }}</a> · No payment has been recorded.</p></div></div>@endif
@endsection