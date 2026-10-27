<!doctype html><html lang="en"><head><meta charset="utf-8">
<title>{{ $invoice['reference'] }} · Commercial Invoice</title>
<style>
@page{size:A4 portrait;margin:12mm}
body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#26372d}
h1{color:#2c5b3d;font-size:23px;margin:12px 0 3px}.brand{font-size:17px;font-weight:bold;color:#37664b}
.meta{width:100%;border-collapse:collapse;margin:12px 0 14px}.meta td{width:50%;padding:5px;vertical-align:top}
.small{font-size:9px;color:#647769}.section{font-size:13px;font-weight:bold;color:#32563e;margin:18px 0 8px}
.items{border-collapse:collapse;width:100%}.items th{background:#e5efe7;padding:7px;color:#284c36;text-align:left}
.items td{padding:7px;border-bottom:1px solid #dfe9e0;vertical-align:top}
.amounts{width:50%;margin:16px 0 0 auto;border-collapse:collapse}.amounts td{padding:4px;text-align:right}
.total{font-weight:bold;background:#eaf3eb;font-size:12px}.foot{margin-top:27px;padding-top:10px;border-top:1px solid #d4ded4;font-size:9px;color:#5f7065}
.note{background:#f3f7f4;padding:9px;line-height:1.4}.num{text-align:right}
</style></head><body>
<div class="brand">{{ $invoice['business']['name'] }}</div><div class="small">Exporter · {{ $invoice['business']['country'] }} · {{ $invoice['business']['code'] }}</div>
<h1>Commercial Invoice</h1>@if(!empty($invoice['due_on']))<p>Payment due: {{ $invoice['due_on'] }}</p>@endif<div class="small">{{ $invoice['reference'] }} · Issued {{ $invoice['issued_at'] }}</div>
<table class="meta"><tr>
<td><strong>Buyer / Consignee</strong><br>{{ $invoice['customer']['name'] }}<br>{{ $invoice['customer']['city'] }} {{ $invoice['customer']['country'] }}<br>{{ $invoice['customer']['email'] }}</td>
<td><strong>Sales order</strong><br>{{ $invoice['order']['reference'] }}<br>
<strong>Shipment</strong><br>{{ $invoice['shipment']['reference'] }}<br>
<strong>Currency</strong><br>{{ $invoice['order']['currency'] }}<br>
<strong>Delivery term</strong><br>{{ $invoice['order']['incoterm'] ?: 'Not agreed' }}</td>
</tr><tr>
<td><strong>Route</strong><br>{{ $invoice['shipment']['origin_airport'] ?: $invoice['shipment']['origin_country'] }} →
{{ $invoice['shipment']['destination_airport'] ?: $invoice['shipment']['destination_country'] }}</td>
<td><strong>Flight / Carrier</strong><br>{{ $invoice['shipment']['flight'] ?: 'Not recorded' }} · {{ $invoice['shipment']['carrier'] ?: 'Not recorded' }}<br>
<strong>Air Waybill</strong><br>{{ $invoice['shipment']['air_waybill'] ?: 'Not recorded' }}</td></tr></table>
<div class="section">Invoiced produce and prices</div>
<table class="items"><thead><tr><th>Product</th><th>Quantity</th><th>Unit price</th><th class="num">Amount ({{ $invoice['order']['currency'] }})</th></tr></thead><tbody>
@foreach($invoice['lines'] as $line)
<tr><td>{{ $line['product'] }}<br><span class="small">{{ $line['sku'] }}</span></td><td>{{ $line['quantity'] }} {{ $line['unit'] }}</td><td>{{ $line['unit_price'] }}</td><td class="num">{{ $line['line_total'] }}</td></tr>
@endforeach</tbody></table>
<table class="amounts">
<tr><td>Goods subtotal</td><td>{{ $invoice['totals']['subtotal'] }}</td></tr>
<tr><td>Discount ({{ $invoice['order']['discount_percent'] }}%)</td><td>-{{ $invoice['totals']['discount'] }}</td></tr>
<tr><td>Tax ({{ $invoice['order']['tax_percent'] }}%)</td><td>{{ $invoice['totals']['tax'] }}</td></tr>
<tr><td>Freight</td><td>{{ $invoice['totals']['freight'] }}</td></tr>
<tr><td>Insurance</td><td>{{ $invoice['totals']['insurance'] }}</td></tr>
<tr class="total"><td>Total ({{ $invoice['order']['currency'] }})</td><td>{{ $invoice['totals']['grand_total'] }}</td></tr>
</table>
<div class="section">Cargo details</div>
<p>{{ $invoice['shipment']['carton_count'] }} cartons · Net {{ $invoice['shipment']['net_weight_kg'] }} kg · Gross {{ $invoice['shipment']['gross_weight_kg'] }} kg</p>
<table class="items"><thead><tr><th>Carton</th><th>Product</th><th>Net (kg)</th><th>Source batches</th></tr></thead><tbody>
@foreach($invoice['cartons'] as $carton)
<tr><td>{{ $carton['reference'] }}</td><td>{{ $carton['product'] }}</td><td>{{ $carton['net_weight_kg'] }}</td><td>{{ implode(', ', $carton['batch_codes']) }}</td></tr>
@endforeach</tbody></table>
@if($invoice['notes'])<div class="note"><strong>Remarks</strong><br>{{ $invoice['notes'] }}</div>@endif
<div class="foot">Prepared using Boosted One ERP from the confirmed sales order and shipment allocation at issuance.
This commercial invoice is not proof of payment, customs clearance, VAT compliance, or certification of origin.
Tax rates, tariffs, customs values and any statutory particulars must be checked by the exporter before use.</div>
</body></html>