@extends('layouts.console')
@section('title', 'Sticker Templates')
@section('content')
<div class="page-intro"><div><span class="eyebrow">TEMPLATES & PRINTING</span><h1>Sticker templates</h1><p>Create exporter-specific Zebra stickers with selectable text, Code 128 barcodes and QR identities.</p></div></div>
<div class="notice"><span>●</span><div><strong>Template configuration — first release</strong><p>Configure text fields, label dimensions, QR codes and barcodes in Zebra ZPL. You can download the label or queue it for an authenticated local print workstation. Logo placement, drag-and-drop design and PDF fallback are separate future studio features.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('cartons.index') }}">Packed cartons</a><a class="secondary-button" href="{{ route('labels.history') }}">Generation history</a></div>
<section class="section-card"><h2>Saved templates</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Template</th><th>Label size</th><th>QR type</th><th>Barcode</th><th>Version</th>@if($canManage)<th></th>@endif</tr></thead><tbody>
@forelse($templates as $template)
<tr><td><strong>{{ $template->name }}</strong></td><td>{{ $template->width_mm }} × {{ $template->height_mm }} mm · {{ $template->dpi }} dpi</td><td>{{ ucfirst($template->qr_level) }}</td><td>{{ $template->include_barcode ? 'Code 128' : 'None' }}</td><td>{{ $template->version }}</td>@if($canManage)<td><a class="action-link" href="{{ route('labels.edit',$template->id) }}">Edit</a></td>@endif</tr>
@empty<tr><td colspan="6" class="empty-msg">No label templates yet.</td></tr>@endforelse
</tbody></table></div>
</section>
@if($canManage)
<section class="section-card form-narrow"><h2>Create sticker template</h2>
<form method="POST" action="{{ route('labels.store') }}" class="form-stack">@csrf
<label class="field"><span>Template name</span><input name="name" value="{{ old('name','Standard 100 x 50 Export Label') }}" maxlength="100" required></label>
<div class="form-columns"><label class="field"><span>Width (mm)</span><input name="width_mm" type="number" min="75" max="150" value="{{ old('width_mm','100') }}" required></label><label class="field"><span>Height (mm)</span><input name="height_mm" type="number" min="45" max="100" value="{{ old('height_mm','50') }}" required></label></div>
<div class="form-columns"><label class="field"><span>Printer resolution</span><select name="dpi"><option value="203">203 dpi</option><option value="300">300 dpi</option></select></label><label class="field"><span>QR code represents</span><select name="qr_level"><option value="carton">Unique carton</option><option value="product">Product</option><option value="batch">Source batch</option><option value="none">No QR code</option></select></label></div>
<input type="hidden" name="include_barcode" value="0"><label class="check-row"><input type="checkbox" name="include_barcode" value="1" checked> Print Code 128 barcode for carton reference</label>
<div class="field"><span>Text fields to include (optional)</span>
<div class="print-fields">
@foreach($fields as $key => $name)<label class="check-row"><input type="checkbox" name="visible_fields[]" value="{{ $key }}" @checked(in_array($key,old('visible_fields',['company','product','carton','net']),true))> {{ $name }}</label>@endforeach
</div></div>
<button class="primary-button">Save template</button></form>
<p class="form-hint">For barcode-only or QR-only stickers, leave all text fields unchecked and select the desired code option. Public QR pages must be enabled before downloading labels that use them.</p>
</section>
@endif
@endsection