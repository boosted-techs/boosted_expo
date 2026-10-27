@extends('layouts.marketing')
@section('title', 'Request Boosted One onboarding')
@section('description', 'Request a Boosted One ERP onboarding consultation for your export company or growing Ugandan business. Tell us about sourcing, stock, packing, shipments and business requirements.')
@section('content')
<section class="page-hero section-container">
    <div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><span>Get started</span></div>
    <span class="eyebrow">GUIDED BUSINESS ONBOARDING</span>
    <h1>Tell us about your operations. <em>We will help you get started.</em></h1>
    <p class="page-lead">An ERP should fit how your team works, not force your team to change everything overnight. Share a few details so we can understand your processes, suggest an appropriate setup and discuss a subscription.</p>
</section>
<section class="section-container onboarding-layout">
    <div class="onboarding-main">
        <div class="form-header"><span class="eyebrow">STEP ONE</span><h2>Request onboarding</h2><p>Complete the form below. We will email information about the available packages and follow up about your business needs. Submitting a request does not create an account or charge your business.</p></div>
        <form class="application" method="POST" action="{{ route('onboarding.apply') }}">
            @csrf
            <h3>Your business</h3>
            <div class="form-grid">
                <label>Registered or trading business name <span aria-hidden="true">*</span><input name="business_name" required minlength="2" maxlength="150" autocomplete="organization" value="{{ old('business_name') }}"></label>
                <label>Your name <span aria-hidden="true">*</span><input name="contact_name" required minlength="2" maxlength="150" autocomplete="name" value="{{ old('contact_name') }}"></label>
                <label>Email address <span aria-hidden="true">*</span><input type="email" name="contact_email" required maxlength="255" autocomplete="email" value="{{ old('contact_email') }}"></label>
                <label>Phone or WhatsApp number <span aria-hidden="true">*</span><input name="contact_phone" type="tel" required minlength="9" maxlength="40" autocomplete="tel" value="{{ old('contact_phone') }}" placeholder="+256…"></label>
                <label>Business type <span aria-hidden="true">*</span>
                    <select name="industry" required>
                        <option value="">Select type</option>
                        @foreach(['export' => 'Export business', 'agriculture' => 'Agribusiness / packhouse', 'manufacturing' => 'Manufacturer / processor', 'distribution' => 'Distributor', 'wholesale' => 'Wholesaler / trader', 'other' => 'Other business'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('industry') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Business size <span aria-hidden="true">*</span>
                    <select name="business_size" required>
                        @foreach(['small' => 'Small business', 'growing' => 'Growing business', 'enterprise' => 'Established enterprise'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('business_size', 'growing') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <h3>Your operations</h3>
            <p>We will email available package information to your address after receiving your request. Our team will help you identify a suitable setup.</p>
            <label>Which operations would you like to manage? <span class="optional">Optional</span>
                <textarea name="requirements" rows="5" maxlength="1500" placeholder="For example: farm sourcing, product batches, warehouse receiving, packing cartons, flight/AWB labels, customer orders or finance reviews.">{{ old('requirements') }}</textarea>
            </label>
            <div class="trap" aria-hidden="true"><label>Leave blank<input name="website" tabindex="-1" autocomplete="off"></label></div>
            <label class="check consent"><input type="checkbox" name="privacy_agreement" value="1" required @checked(old('privacy_agreement'))><span>I have read the <a href="{{ route('privacy') }}" target="_blank" rel="noopener noreferrer">Privacy Policy</a> and understand that Boosted Technologies Limited will use the information above to respond to my onboarding request.</span></label>
            <button class="button button-primary form-submit" type="submit">Submit onboarding request <span aria-hidden="true">↗</span></button>
            <p class="form-fine">Only Boosted platform administrators approve new company workspaces. Your subscription terms and payment instructions are confirmed separately.</p>
        </form>
    </div>
    <aside class="onboarding-aside">
        <span class="eyebrow">WHAT HAPPENS NEXT?</span><h2>Simple, guided onboarding.</h2>
        <div class="aside-step"><span>01</span><div><strong>We review your request</strong><p>We learn about your work, departments, inventory and shipping or documentation needs.</p></div></div>
        <div class="aside-step"><span>02</span><div><strong>We email package information</strong><p>You receive an overview of the available options. We then discuss suitable modules, pricing and responsibilities for your business.</p></div></div>
        <div class="aside-step"><span>03</span><div><strong>Your business receives an invitation</strong><p>Once approved, your authorised business owner receives access to the workspace.</p></div></div>
        <div class="aside-step"><span>04</span><div><strong>Your team starts working</strong><p>Set up the catalogue, suppliers, customers and staff permissions before entering real transactions.</p></div></div>
        <p class="aside-note">Already have an account? <a href="{{ route('login') }}">Sign in here →</a></p>
    </aside>
</section>
@endsection