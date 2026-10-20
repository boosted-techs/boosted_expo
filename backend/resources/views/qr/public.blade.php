<!doctype html><html lang="en" data-theme="light">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#37664b">
<title>{{ $title }} · {{ $business->name }}</title>
<link rel="stylesheet" href="/assets/expo.css"><link rel="stylesheet" href="/assets/modules.css">
</head>
<body class="public-qr-body"><main class="public-qr-card">
<span class="eyebrow">PRODUCT TRACEABILITY</span>
@if($hasPhoto)<img class="public-product-photo" src="{{ route('qr.photo', $qr->public_token) }}" alt="Product photograph">@endif
<h1>{{ $title }}</h1><p>{{ $description }}</p>
<div class="public-fields">
<div class="record"><strong>Exporter</strong><span>{{ $business->name }}</span></div>
<div class="record"><strong>Country</strong><span>{{ $business->country_code }}</span></div>
@foreach($fields as $field => $value)<div class="record"><strong>{{ $field }}</strong><span>{{ $value }}</span></div>@endforeach
</div>
<p class="form-hint">Information published by the exporting business. A QR code identifies the record but does not by itself certify product origin, safety or delivery.</p>
<div class="public-footer">Powered by <strong>Boosted Export ERP</strong></div>
</main></body></html>