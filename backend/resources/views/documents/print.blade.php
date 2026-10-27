<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $document['document']['number'] }} · {{ $typeLabels[$document['document']['type']] }}</title>
<style>
@page { size: {{ $document['template']['paper_size'] }} {{ $document['template']['orientation'] }}; margin: 10mm; }
* { box-sizing: border-box; }
body { font-family: DejaVu Sans, Arial, sans-serif; color: #1e3028; font-size: 10px; margin: 0; background:#fff; }
.doc-top { width: 100%; border-bottom: 3px solid #37664b; padding-bottom: 8px; margin-bottom: 10px; }
.brand { color: #37664b; font-size: 19px; font-weight: bold; letter-spacing: -0.3px; }
.strap { text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color:#697d70; margin-top: 4px; }
.doc-number { font-size: 10px; color:#55705d; font-weight:bold; }
.title { font-size: 20px; margin: 11px 0 4px; letter-spacing:-.4px; color:#213f30; }
.sub { color: #67776b; margin: 0 0 7px; }
.header-table { width: 100%; border-collapse: separate; border-spacing: 0 3px; margin: 4px 0 11px; }
.header-table td { vertical-align: top; padding: 2px 8px 2px 0; }
.h-label { font-size: 8px; text-transform: uppercase; color:#667a6c; font-weight:bold; margin-bottom: 4px; }
.h-value { font-size: 10px; font-weight:bold; }
.items { width:100%; border-collapse: collapse; margin-top: 8px; table-layout: fixed; }
.items th { background: #e6f0e8; color: #24543b; padding: 6px 5px; border-bottom: 1px solid #a3c2aa; font-size: 8px; text-align: left; word-wrap: break-word; }
.items td { padding: 6px 5px; border-bottom: 1px solid #e2e9e3; font-size: 9px; vertical-align: middle; word-wrap: break-word; overflow-wrap: break-word; }
.items tr { page-break-inside: avoid; }
.items thead { display: table-header-group; }
.total { background: #edf4ee; font-weight: bold; }
.stats { width:100%; margin:11px 0; border-spacing: 7px 0; }
.stats td { width:25%; background: #f0f6f0; border: 1px solid #d3e2d7; padding:8px 12px; }
.stat-label { font-size:8px; text-transform:uppercase; color:#5c7967; }
.stat-value { font-size:16px; font-weight:bold; color:#28533a; margin-top:5px; }
.section { font-size:12px; color:#27513a; margin:13px 0 6px; }
.note { background:#f7f9f7; border-left:3px solid #bdd4c1; padding:12px; margin-top:18px; line-height:1.5; }
.cargo-line { border-bottom:1px solid #dde7e0; padding:12px 0; page-break-inside: avoid; }
.cargo-name { font-weight:bold; font-size:11px; }
.muted { color:#5c7263; }
.footer { font-size:8px; border-top:1px solid #d6e5da; padding-top:4px; margin-top:5px; color:#728375; line-height:1.2; }
.screen-only { text-align:right; padding:10px; background:#edf5ef; }
@media print { .screen-only { display:none; } }
@media screen {
  body { max-width:1120px; padding:30px 40px; margin:25px auto; box-shadow:0 3px 20px rgba(0,0,0,.09); }
}
</style>
</head>
<body>
<table class="doc-top"><tr>
<td style="width:60%">
<div class="brand">{{ $document['exporter']['name'] }}</div>
<div class="strap">Export documentation · {{ $document['exporter']['country'] }}</div>
</td>
<td style="text-align:right">
<div class="doc-number">{{ $document['document']['number'] }}</div>
<div class="strap">Generated {{ $document['issued_at'] }}</div>
</td>
</tr></table>
<h1 class="title">{{ $typeLabels[$document['document']['type']] }}</h1>
<p class="sub">{{ $document['shipment']['reference'] }} · Shipment status when issued: {{ strtoupper($document['shipment']['status']) }}</p>
<table class="header-table">
<tr>
<td style="width:50%">
<div class="h-label">Consignee / Buyer</div>
<div class="h-value">{{ $document['consignee']['name'] }}</div>
<div class="muted">{{ $document['consignee']['city'] }} {{ $document['consignee']['country'] }}</div>
</td>
<td><div class="h-label">Shipment reference</div><div class="h-value">{{ $document['shipment']['reference'] }}</div></td>
</tr>
@php $headerFields=array_values(array_filter($document['template']['headers'],fn ($field)=>$field!=='customer')); @endphp
@foreach(array_chunk($headerFields,3) as $headers)
<tr>
@foreach($headers as $field)
<td><div class="h-label">{{ $headerLabels[$field] ?? $field }}</div>
<div class="h-value">{{ $document['header'][$field] ?? 'Not recorded' }}</div></td>
@endforeach
@for($i=count($headers);$i<3;$i++)<td></td>@endfor
</tr>
@endforeach
</table>
@if($document['document']['type']==='packing_list')
<h2 class="section">Packed produce — carton manifest</h2>
<table class="items">
<thead><tr>@foreach($document['template']['columns'] as $col)<th>{{ $columnLabels[$col] ?? $col }}</th>@endforeach</tr></thead>
<tbody>
@foreach($document['lines'] as $line)<tr>
@foreach($document['template']['columns'] as $col)<td>{{ $line[$col] ?? '—' }}</td>@endforeach
</tr>@endforeach
</tbody></table>
@else
<h2 class="section">Cargo handover details</h2>
<p>These packed cartons are recorded for dispatch under the shipment reference above. The movement is recorded by the exporter; this notice is not carrier acceptance evidence.</p>
@foreach($document['product_summary'] as $row)
<div class="cargo-line">
<div class="cargo-name">{{ $row['product'] }} <span class="muted">· {{ $row['sku'] }}</span></div>
<div class="muted">{{ $row['cartons'] }} carton(s) · {{ $row['net'] }} kg net · {{ $row['gross'] }} kg gross</div>
</div>
@endforeach
@if($document['shipment']['actual_departure'])
<div class="note"><strong>Departure recorded by exporter:</strong> {{ $document['shipment']['actual_departure'] }}</div>
@endif
@endif
<table class="stats"><tr>
<td><div class="stat-label">Total cartons</div><div class="stat-value">{{ $document['totals']['cartons'] }}</div></td>
<td><div class="stat-label">Net weight</div><div class="stat-value">{{ $document['totals']['net'] }} kg</div></td>
<td><div class="stat-label">Tare weight</div><div class="stat-value">{{ $document['totals']['tare'] }} kg</div></td>
<td><div class="stat-label">Gross weight</div><div class="stat-value">{{ $document['totals']['gross'] }} kg</div></td>
</tr></table>
@if($document['document']['type']==='packing_list')
<h2 class="section">Product totals</h2>
<table class="items"><thead><tr><th>Produce</th><th>Product code</th><th>Cartons</th><th>Net (kg)</th><th>Gross (kg)</th></tr></thead>
<tbody>@foreach($document['product_summary'] as $row)
<tr><td>{{ $row['product'] }}</td><td>{{ $row['sku'] }}</td><td>{{ $row['cartons'] }}</td><td>{{ $row['net'] }}</td><td>{{ $row['gross'] }}</td></tr>
@endforeach</tbody></table>
@endif
@if($document['shipment']['notes'])
<div class="note"><strong>Shipment remarks:</strong> {{ $document['shipment']['notes'] }}</div>
@endif
<div class="footer">
Document {{ $document['document']['number'] }} · Revision {{ $document['document']['version'] }} ·
Prepared through Boosted One ERP.<br>
Figures reflect the cartons allocated when this document was issued.
Verify all details against actual weights, airline acceptance and applicable export regulations.
This document is not a certificate of origin, phytosanitary certificate, customs clearance, or commercial invoice.
</div>
</body></html>
