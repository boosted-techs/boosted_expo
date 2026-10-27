@extends('layouts.public')
@section('title', 'Platform business administration')
@section('content')
<span class="eyebrow">BOOSTED PLATFORM</span>
<h1>Exporting businesses</h1>
<p><a href="{{ route('platform.billing.index') }}">Manage onboarding requests, subscriptions and invoices →</a></p>
<p class="subhead">Only a Boosted platform administrator can add a company and invite its first owner.</p>
<section class="section-card">
 <h2>Register a business</h2>
 <form action="{{ route('platform.businesses.store') }}" method="POST" class="form-stack">@csrf
  <label class="field"><span>Business name</span><input name="name" value="{{ old('name') }}" required maxlength="150"></label>
  <label class="field"><span>Business code</span><input name="code" value="{{ old('code') }}" required placeholder="AFRIMEX" pattern="[A-Za-z0-9_-]+" maxlength="50"></label>
  <div class="form-columns">
    <label class="field"><span>Country code</span><input name="country_code" value="{{ old('country_code','UG') }}" maxlength="2" minlength="2" required></label>
    <label class="field"><span>Base currency</span><input name="base_currency" value="{{ old('base_currency','UGX') }}" maxlength="3" minlength="3" required></label>
  </div>
  <label class="field"><span>Timezone</span><input name="timezone" value="{{ old('timezone','Africa/Kampala') }}" required></label>
  <label class="field"><span>Default QR identity</span><select name="default_qr_level"><option value="product">Product</option><option value="batch" selected>Batch</option><option value="carton">Carton</option></select></label>
  <label class="field"><span>Owner's email address</span><input name="owner_email" type="email" value="{{ old('owner_email') }}" required autocomplete="off"></label>
  <button type="submit" class="primary-button">Create and invite owner</button>
 </form>
</section>
<section class="section-card">
 <h2>Registered businesses</h2>
 @forelse($businesses as $business)
 <div class="record"><div><strong>{{ $business->name }}</strong><small>{{ $business->code }} · {{ $business->base_currency }} · {{ $business->timezone }}</small></div><span class="tiny-pill">{{ $business->is_active ? 'Active' : 'Suspended' }}</span></div>
 @empty<p class="empty-msg">No exporters registered yet.</p>@endforelse
 {{ $businesses->links() }}
</section>
<form method="POST" action="{{ route('logout') }}" class="subtle-form">@csrf<button class="text-button">Sign out</button></form>
@endsection