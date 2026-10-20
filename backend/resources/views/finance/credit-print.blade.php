<!doctype html><html lang="en"><head><meta charset="utf-8">
<title>{{ $note->reference }} · Full Credit Note</title><style>
@page{size:A4 portrait;margin:16mm}
body{font-family:DejaVu Sans,Arial,sans-serif;color:#293b31;font-size:11px}
h1{color:#2d6242;font-size:23px}h2{font-size:13px;color:#325b43}
.brand{font-size:18px;font-weight:bold;color:#37664b}
table{border-collapse:collapse;width:100%;margin-top:17px}
td,th{padding:11px;text-align:left;border-bottom:1px solid #dce9dd}
th{background:#e7f1e9}.sm{font-size:9px;color:#68796c}
.detail{padding:10px;border:1px solid #dce9dd;margin-top:17px}
.footer{font-size:9px;color:#667a6b;margin-top:36px;border-top:1px solid #dce9dd;padding-top:12px}
</style></head><body>
<div class="brand">{{ $note->data_snapshot['business']['name']??'Exporter' }}</div>
<h1>Full Credit Note</h1>
<p>{{ $note->reference }} · Issued {{ $note->issued_at?->format('d M Y') }}</p>
<table><tbody>
<tr><th>Original invoice</th><td>{{ $note->data_snapshot['invoice_reference'] }}</td></tr>
<tr><th>Customer</th><td>{{ $note->data_snapshot['customer']['name']??'Buyer' }}</td></tr>
<tr><th>Original invoice amount</th><td>{{ $note->currency }} {{ $note->amount }}</td></tr>
<tr><th>Full value credited</th><td><strong>{{ $note->currency }} {{ $note->amount }}</strong></td></tr>
<tr><th>Original journal</th><td>{{ $note->data_snapshot['original_journal'] }}</td></tr>
</tbody></table>
<div class="detail"><h2>Reason for correction</h2><p>{{ $note->reason }}</p></div>
<p class="sm">The original invoice remains in the transaction history. The reversing financial journal is retained separately.</p>
<div class="footer">Full commercial correction only. This document does not certify a customer refund, delivery return, customs correction or statutory tax credit. The exporter must check jurisdiction-specific requirements before external use.</div>
</body></html>