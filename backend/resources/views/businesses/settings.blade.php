@extends('layouts.console')
@section('title', 'Company Preferences')
@section('content')
<div class="page-intro"><div><span class="eyebrow">BUSINESS SETTINGS</span><h1>Company Preferences</h1><p>Manage your company defaults.</p></div></div>
<div class="settings-grid">
<div class="section-card form-narrow">
<h2>QR code identity</h2><p class="muted">Choose what new QR codes identify.</p>
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
<p class="form-hint">Financial postings require independent approval.</p>
</div>
<section class="section-card form-narrow">
<h2>Trading currencies</h2>
<p class="muted">Select trading currencies. Reports use {{ $business->base_currency }}.</p>
@if($canChangePolicy)
<form method="POST" action="{{ route('business.currencies.update') }}" class="form-stack">@csrf @method('PUT')
<input type="hidden" name="enabled_currencies[]" value="{{ $business->base_currency }}">
@foreach($currencyCatalogue as $code => $name)
<label class="check-row"><input type="checkbox" name="enabled_currencies[]" value="{{ $code }}"
@checked(in_array($code, old('enabled_currencies', $enabledCurrencies), true)) @disabled($code === $business->base_currency)>
{{ $code }} · {{ $name }}{{ $code === $business->base_currency ? ' (reporting currency)' : '' }}</label>
@endforeach
<button class="primary-button">Save trading currencies</button></form>
@else
<p>{{ implode(', ', $enabledCurrencies) }}. Only the business owner can change these currencies.</p>
@endif
<p class="form-hint">Approve a dated exchange rate before using a foreign currency. Existing records keep their original currency.</p>
</section>
<section class="section-card form-narrow">
<h2>Operating approvals & buyer access</h2>
<p class="muted">Set dispatch approvals and buyer link expiry. Only the owner can make changes.</p>
@if($canChangePolicy)
<form method="POST" action="{{ route('business.policies.update') }}" class="form-stack">@csrf
<input type="hidden" name="dispatch_approval_required" value="0">
<label class="check-row"><input type="checkbox" name="dispatch_approval_required" value="1"
@checked(old('dispatch_approval_required',$policy?->dispatch_approval_required ?? false))>
Require independent owner/administrator approval before shipment dispatch</label>
<label class="field"><span>Buyer tracking link lifetime (days)</span>
<input name="buyer_link_days" type="number" min="1" max="30"
value="{{ old('buyer_link_days',$policy?->buyer_link_days ?? 7) }}" required></label>
<button class="primary-button">Save operating policies</button></form>
@else
<div class="record"><strong>Dispatch approval</strong><span>{{ ($policy?->dispatch_approval_required??false)?'Required':'Standard logistics workflow' }}</span></div>
<div class="record"><strong>Buyer link lifetime</strong><span>{{ $policy?->buyer_link_days??7 }} days</span></div>
@endif
</section>
</div>
@endsection
