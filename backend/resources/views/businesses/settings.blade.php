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
<p class="form-hint">Sensitive financial postings always require independent review. These operating preferences cannot bypass statutory or finance approvals.</p>
</div>
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