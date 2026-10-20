<!doctype html><html lang="en"><head><meta charset="utf-8">
<title>{{ $quote->reference }} · Quotation</title>
<style>
@page{size:A4 portrait;margin:14mm}body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#21382b}
h1{font-size:21px;color:#325b40}h2{font-size:13px;margin-top:28px}.brand{font-size:18px;font-weight:bold;color:#37664b}
.muted{color:#6c7c6c}.meta{width:100%;border-collapse:collapse;margin:12px 0 22px}.meta td{width:50%;padding:6px}
table.items{width:100%;border-collapse:collapse}table.items th{background:#e7efe8;color:#284c38;text-align:left;padding:9px}
table.items td{border-bottom:1px solid #dbe5dc;padding:8px;vertical-align:top}
.sum{text-align:right;padding-top:14px;line-height:1.9}.note{margin-top:20px;background:#f3f6f3;padding:12px;line-height:1.5}
.footer{margin-top:35px;border-top:1px solid #cedfcf;padding-top:8px;color:#758477;font-size:9px}
</style></head><body>
<div class="brand">{{ $exporter }} · PRICE QUOTATION</div>
<h1>Price Quotation</h1><div class="muted">{{ $quote->reference }} · Revision {{ $quote->revision }}</div>
<table class="meta"><tr><td><strong>Buyer</strong><br>{{ $quote->salesRequest->customer->name }}<br>{{ $quote->salesRequest->customer->city }}, {{ $quote->salesRequest->customer->country_code }}</td>
<td><strong>Request</strong><br>{{ $quote->salesRequest->reference }}<br>Issued {{ $quote->issued_at?->format('d M Y') }}</td></tr>
<tr><td><strong>Currency</strong><br>{{ $quote->currency }}</td><td><strong>Valid until</strong><br>{{ $quote->valid_until?->format('d M Y') ?: 'Not specified' }}</td></tr>
<tr><td><strong>Delivery term</strong><br>{{ $quote->incoterm ?: 'To be agreed' }}</td><td><strong>Quotation status</strong><br>{{ strtoupper($quote->status) }}</td></tr></table>
<table class="items"><thead><tr><th>Product</th><th>Qty / unit</th><th>Unit price ({{ $quote->currency }})</th><th>Total ({{ $quote->currency }})</th></tr></thead><tbody>
@foreach($quote->lines as $line)<tr><td>{{ $line->product_name ?: $line->product->name }}<br><span class="muted">{{ $line->product_sku ?: $line->product->sku }}</span></td><td>{{ $line->quantity }} {{ $line->unit ?: $line->product->base_unit }}</td><td>{{ $line->unit_price }}</td><td>{{ $line->line_total }}</td></tr>@endforeach
</tbody></table>
<div class="sum">Subtotal: {{ $quote->currency }} {{ $quote->subtotal }}<br>
Discount ({{ $quote->discount_percent }}%): {{ $quote->discount_amount }}<br>
Tax ({{ $quote->tax_percent }}%): {{ $quote->tax_amount }}<br>
<strong>Total quotation: {{ $quote->currency }} {{ $quote->grand_total }}</strong></div>
@if($quote->notes)<div class="note"><strong>Terms and notes:</strong> {{ $quote->notes }}</div>@endif
<div class="footer">This quotation is a commercial price offer, not a commercial invoice, receipt, regulatory certificate or confirmation of payment. Tax applicability and agreed terms should be verified with the parties.</div>
</body></html>