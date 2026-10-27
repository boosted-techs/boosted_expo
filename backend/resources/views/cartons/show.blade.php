@extends('layouts.console')
@section('title', 'Carton Details')
@section('content')
<div class="page-intro"><div><span class="eyebrow">PACKED CARTON</span><h1>{{ $carton->carton_code }}</h1><p>{{ $carton->packingJob?->product?->name ?: 'Unlinked carton' }} · {{ ucfirst($carton->status) }}</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('cartons.index') }}">All cartons</a>@if($carton->packingJob)<a class="secondary-button" href="{{ route('packing.show',$carton->packing_job_id) }}">Packing job</a>@endif<a class="secondary-button" href="{{ route('labels.index') }}">Sticker templates</a></div>
@if($carton->shipmentAllocation?->shipment && app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'shipments.view'))
<div class="notice"><span>●</span><div><strong>Allocated to export shipment</strong><p><a class="action-link" href="{{ route('shipments.show',$carton->shipmentAllocation->shipment_id) }}">{{ $carton->shipmentAllocation->shipment->reference }}</a> · {{ ucfirst($carton->shipmentAllocation->shipment->status) }}</p></div></div>
@endif
<div class="split-panels">
@if($canPrint)<div class="section-card"><a class="primary-button" href="{{ route('labels.print',['carton'=>$carton->id]) }}">Preview & print sticker</a><p class="form-hint">Create a current label, download its PDF or send it to the shared workstation.</p></div>@endif
<section class="section-card"><h2>Carton details</h2>
<div class="record"><strong>Product</strong><span>{{ $carton->packingJob?->product?->name ?: '—' }}</span></div>
<div class="record"><strong>Grade</strong><span>{{ $carton->grade ?: '—' }}</span></div>
<div class="record"><strong>Packaging</strong><span>{{ $carton->packaging_type ?: '—' }}</span></div>
<div class="record"><strong>Net weight</strong><span>{{ $carton->net_weight_kg }} kg</span></div>
<div class="record"><strong>Tare</strong><span>{{ $carton->tare_weight_kg }} kg</span></div>
<div class="record"><strong>Gross weight</strong><span>{{ $carton->gross_weight_kg }} kg</span></div>
<div class="record"><strong>Packed on</strong><span>{{ $carton->packed_at?->format('d M Y H:i') ?: '—' }}</span></div>
<h2 style="margin-top:24px">Source batches</h2>
@forelse($carton->contents as $line)<div class="record"><div><strong>{{ $line->batch->batch_code }}</strong><small>{{ $line->batch->source_reference ?: 'Origin not specified' }}</small></div><span>{{ $line->quantity }} {{ $carton->packingJob?->product?->base_unit }}</span></div>
@empty<p class="empty-msg">No batch lines on this carton.</p>@endforelse
</section>
<section class="section-card"><h2>QR visibility</h2>
<p class="muted">A carton QR identifies this specific carton. Information is private by default. Enable the public page only if it should be visible to recipients.</p>
<div class="record"><strong>Online carton page</strong><span class="tiny-pill">{{ $qr->public_enabled ? 'Public' : 'Hidden' }}</span></div>
@if($qr->public_enabled)<a class="action-link" href="{{ route('qr.public',$qr->public_token) }}" target="_blank" rel="noopener noreferrer">Open public carton information ↗</a>@endif
@if($canPublish)<form method="POST" action="{{ route('cartons.qr.visibility',$carton->id) }}" class="form-stack" style="margin-top:16px">@csrf @method('PUT')
<input type="hidden" name="public_enabled" value="{{ $qr->public_enabled ? 0 : 1 }}"><button class="secondary-button">{{ $qr->public_enabled ? 'Hide public carton page' : 'Enable public carton page' }}</button></form>@endif
<h2 style="margin-top:30px">Download Zebra label</h2>
<p class="muted">Choose a saved sticker template and download its ZPL file for a compatible Zebra printer. This does not send a job directly to the printer.</p>
@if($canPrint)
@forelse($templates as $template)
<div class="record"><div><strong>{{ $template->name }}</strong><small>{{ $template->width_mm }} × {{ $template->height_mm }} mm · {{ $template->dpi }} dpi · {{ ucfirst($template->qr_level) }} QR</small></div>
<form method="POST" action="{{ route('labels.generate',['carton'=>$carton->id,'template'=>$template->id]) }}">@csrf<button class="secondary-button">Generate ZPL</button></form>
</div>
@empty<p class="empty-msg">No templates yet. <a class="action-link" href="{{ route('labels.index') }}">Open Sticker Templates</a>.</p>@endforelse
@endif
<p class="form-hint">Private QR templates work without publishing business data. Public QR templates require explicit publication. Mixed-batch cartons need a carton or product QR.</p>
</section>
</div>
@endsection
