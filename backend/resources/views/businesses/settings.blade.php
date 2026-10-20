@extends('layouts.console')
@section('title', 'Company Preferences')
@section('content')
<div class="page-intro"><div><span class="eyebrow">BUSINESS SETTINGS</span><h1>Company Preferences</h1><p>Control defaults used throughout {{ $business->name }}.</p></div></div>
<div class="section-card form-narrow">
<h2>QR code identity</h2><p class="muted">Individual templates will be able to override this default when the full printing module is available.</p>
<form method="POST" action="{{ route('business.settings.update') }}" class="form-stack">@csrf @method('PUT')
<label class="field"><span>Default QR level</span><select name="default_qr_level" required>
@foreach(['product'=>'Product','batch'=>'Batch','carton'=>'Carton'] as $value=>$label)
<option value="{{ $value }}" @selected($business->default_qr_level === $value)>{{ $label }}</option>
@endforeach
</select></label>
<button class="primary-button" type="submit">Save preference</button>
</form>
</div>
<div class="section-card form-narrow"><h2>Company details</h2>
<div class="record"><div><strong>{{ $business->name }}</strong><small>Company code: {{ $business->code }}</small></div></div>
<div class="record"><div><strong>Reporting currency</strong><small>{{ $business->base_currency }} · {{ $business->timezone }}</small></div></div>
<p class="form-hint">Other company settings and policy management will be added in subsequent development passes.</p>
</div>
@endsection