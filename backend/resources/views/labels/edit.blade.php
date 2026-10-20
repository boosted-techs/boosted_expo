@extends('layouts.console')
@section('title', 'Edit Label Template')
@section('content')
<div class="page-intro"><div><span class="eyebrow">TEMPLATE SETTINGS</span><h1>Edit sticker template</h1><p>{{ $template->name }} · Version {{ $template->version }} — saved generations retain their original ZPL.</p></div></div>
<section class="section-card form-narrow">
<form method="POST" action="{{ route('labels.update',$template->id) }}" class="form-stack">@csrf @method('PUT')
<label class="field"><span>Template name</span><input name="name" value="{{ old('name',$template->name) }}" required maxlength="100"></label>
<div class="form-columns"><label class="field"><span>Width (mm)</span><input name="width_mm" type="number" min="75" max="150" value="{{ old('width_mm',$template->width_mm) }}" required></label><label class="field"><span>Height (mm)</span><input name="height_mm" type="number" min="45" max="100" value="{{ old('height_mm',$template->height_mm) }}" required></label></div>
<div class="form-columns"><label class="field"><span>Resolution</span><select name="dpi">@foreach([203,300] as $dpi)<option value="{{ $dpi }}" @selected(old('dpi',$template->dpi)==$dpi)>{{ $dpi }} dpi</option>@endforeach</select></label>
<label class="field"><span>QR code</span><select name="qr_level">@foreach(['carton'=>'Unique carton','product'=>'Product','batch'=>'Source batch','none'=>'No QR'] as $level=>$label)<option value="{{ $level }}" @selected(old('qr_level',$template->qr_level)===$level)>{{ $label }}</option>@endforeach</select></label></div>
<input type="hidden" name="include_barcode" value="0"><label class="check-row"><input type="checkbox" name="include_barcode" value="1" @checked(old('include_barcode',$template->include_barcode))> Print Code 128 barcode</label>
<div class="field"><span>Text fields</span><div class="print-fields">
@foreach($fields as $key=>$label)<label class="check-row"><input type="checkbox" name="visible_fields[]" value="{{ $key }}" @checked(in_array($key,old('visible_fields',$template->visible_fields),true))> {{ $label }}</label>@endforeach
</div></div>
<div class="form-actions"><button class="primary-button">Save new version</button><a class="secondary-button" href="{{ route('labels.index') }}">Cancel</a></div>
</form></section>
@endsection