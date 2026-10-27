@extends('layouts.console')
@section('title', 'Company Preferences')
@section('content')
<div class="page-intro"><div><span class="eyebrow">BUSINESS SETTINGS</span><h1>Company Preferences</h1><p>Control defaults used throughout {{ $business->name }}.</p></div></div>
<div class="section-card form-narrow">
<h2>QR code identity</h2><p class="muted">Sticker templates choose their own product, batch or carton identity.</p>
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
<p class="form-hint">Sensitive financial postings always require independent review. These operating preferences cannot bypass statutory or finance approvals.</p>
</div>
<section class="section-card form-narrow">
<h2>Trading currencies</h2>
<p class="muted">Choose the currencies used for new customers, purchases, quotations, costs, banks and manual exchange rates. The reporting currency remains {{ $business->base_currency }}. Previous documents, balances and approved rates keep their original currency.</p>
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
<p class="form-hint">Enabling a foreign currency does not create an exchange rate. Finance still requires an independently approved dated manual rate. Existing invoices, bills and bank statements can still be settled or reconciled in their original currency.</p>
</section>
<section class="section-card form-narrow">
<h2>Operating approvals & buyer access</h2>
<p class="muted">Only the company owner can change these safeguards. An independent reviewer may be required before a shipment leaves.</p>
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
@endsection
