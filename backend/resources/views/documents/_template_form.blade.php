<div class="form-stack">
<label class="field"><span>Template name</span><input name="name" value="{{ old('name',$template?->name) }}" maxlength="100" required placeholder="e.g. Airline Packing List"></label>
<div class="form-columns">
<label class="field"><span>Document purpose</span>
<select name="type" required id="doc-type">
@foreach($types as $type=>$name)<option value="{{ $type }}" @selected(old('type',$template?->type)===$type)>{{ $name }}</option>@endforeach
</select><small>Packing lists use a carton table. Dispatch notes use a cargo summary without a carton table.</small></label>
<label class="field"><span>Paper size</span><select name="paper_size" required>
@foreach(['A4','Letter'] as $size)<option value="{{ $size }}" @selected(old('paper_size',$template?->paper_size ?? 'A4')===$size)>{{ $size }}</option>@endforeach
</select></label>
</div>
<label class="field"><span>Page orientation</span><select name="orientation" required>
<option value="portrait" @selected(old('orientation',$template?->orientation)==='portrait')>Portrait</option>
<option value="landscape" @selected(old('orientation',$template?->orientation ?? 'landscape')==='landscape')>Landscape</option>
</select></label>
<div class="field"><span>Shipment details to include</span>
<div class="print-fields">
@foreach($headers as $key=>$name)
<label class="check-row"><input type="checkbox" name="header_fields[]" value="{{ $key }}"
@checked(in_array($key,old('header_fields',$template?->header_fields ?? ['customer','carrier','flight','awb','route','schedule']),true))>
{{ $name }}</label>
@endforeach
</div></div>
<div class="field" id="packing-columns"><span>Packing-list table columns</span>
<p class="form-hint">Choose the columns important to this buyer. Every carton is listed individually; totals appear automatically.</p>
<div class="print-fields">
@foreach($columns as $key=>$name)
<label class="check-row"><input type="checkbox" name="visible_columns[]" value="{{ $key }}"
@checked(in_array($key,old('visible_columns',$template?->visible_columns ?? ['carton','product','farmer','batches','boxes','net','tare','gross']),true))>
{{ $name }}</label>
@endforeach
</div></div>
<p class="form-hint">Template changes apply to new documents. Issued documents keep their original details.</p>
</div>
<script src="/assets/document-template-ui.js" defer></script>