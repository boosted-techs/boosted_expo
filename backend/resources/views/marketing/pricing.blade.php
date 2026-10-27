@extends('layouts.marketing')
@section('title', 'Monthly, quarterly and annual ERP subscriptions')
@section('description', 'Explore Boosted One subscription options for Ugandan exporters and growing businesses. Choose monthly, quarterly or annual billing with agreed modules and onboarding.')
@section('content')
<section class="page-hero section-container">
    <div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><span>Pricing</span></div>
    <span class="eyebrow">SUBSCRIPTIONS THAT MATCH YOUR OPERATIONS</span>
    <h1>Start with what your business needs. <em>Grow from there.</em></h1>
    <p class="page-lead">Choose a monthly, quarterly or annual subscription. We confirm the relevant modules, users, support arrangement and final UGX price in writing before activation.</p>
</section>
<section class="section-container pricing-section">
    <div class="section-heading"><div><span class="eyebrow">PLANS</span><h2>Subscriptions for different stages of business</h2><p class="section-intro">Plan descriptions below explain intended fit. Your exact access and commercial terms are confirmed through an individual quotation and agreement.</p></div></div>
    <div class="pricing-grid">
        @forelse($plans as $plan)
        <article class="pricing-card">
            <span class="pill">{{ $plan->code }}</span>
            <h3>{{ $plan->name }}</h3>
            <p>{{ $plan->description ?: 'Your ERP setup and modules are discussed during onboarding.' }}</p>
            <div class="price-detail">
                <span>Monthly</span><strong>{{ $plan->monthly_price_ugx === null ? 'Request a quote' : 'UGX '.number_format($plan->monthly_price_ugx) }}</strong>
                <span>Quarterly</span><strong>{{ $plan->quarterly_price_ugx === null ? 'Request a quote' : 'UGX '.number_format($plan->quarterly_price_ugx) }}</strong>
                <span>Annually</span><strong>{{ $plan->annual_price_ugx === null ? 'Request a quote' : 'UGX '.number_format($plan->annual_price_ugx) }}</strong>
            </div>
            <a class="button button-outline button-full" href="{{ route('site.onboarding') }}">Discuss {{ $plan->name }} ↗</a>
        </article>
        @empty
        @foreach([
          ['Starter', 'For smaller operations building better daily purchasing, stock and sales records.', 'Smaller businesses'],
          ['Business', 'For growing exporters connecting purchasing, warehouse work, orders, documents and internal controls.', 'Growing export teams'],
          ['Enterprise', 'For established businesses needing broader roles, operational reviews and tailored configuration.', 'Complex operations']
        ] as $plan)
        <article class="pricing-card">
            <span class="pill">{{ $plan[2] }}</span>
            <h3>{{ $plan[0] }}</h3>
            <p>{{ $plan[1] }}</p>
            <div class="price-prompt"><span>Monthly, quarterly or annual</span><strong>Request a quote</strong><p>No published fixed price yet. We agree scope and charges before you pay.</p></div>
            <a class="button button-outline button-full" href="{{ route('site.onboarding') }}">Discuss {{ $plan[0] }} ↗</a>
        </article>
        @endforeach
        @endforelse
    </div>
    <p class="muted-note">Published amounts, when configured, are shown in UGX for the stated billing period. Applicable taxes, setup services, module limits and renewal rules are confirmed on the official quote or invoice.</p>
</section>
<section class="subtle-section"><div class="section-container section-space">
    <div class="section-heading"><div><span class="eyebrow">THE PROCESS</span><h2>How subscription billing works</h2></div></div>
    <div class="step-grid">
        <article class="step-card"><span class="step-label">01 / DISCUSS</span><h3>Choose an appropriate setup</h3><p>Tell us which daily operations matter, how your team works and whether you prefer monthly, quarterly or annual billing.</p></article>
        <article class="step-card"><span class="step-label">02 / AGREE</span><h3>Receive clear commercial terms</h3><p>The agreed price, modules, service period, tax treatment and payment details are documented before invoicing.</p></article>
        <article class="step-card"><span class="step-label">03 / PAY</span><h3>Pay an official invoice</h3><p>Payments are recorded after verification against the details supplied by Boosted Technologies. Your invoices remain available to authorised business staff.</p></article>
        <article class="step-card"><span class="step-label">04 / RENEW</span><h3>Continue your operations</h3><p>Renewal invoices and due dates are managed through the subscription lifecycle, with access governed by the agreed service terms.</p></article>
    </div>
</div></section>
<section class="section-container section-space">
    <div class="split-insight"><div><span class="eyebrow">COMMON QUESTIONS</span><h2>A few things to know before you subscribe</h2></div><div class="faq">
        <details><summary>Can we start without all the export modules?</summary><p>Yes. During onboarding we identify which features are relevant for your operation and confirm the agreed scope. A smaller business does not have to use every export workflow.</p></details>
        <details><summary>Can we choose quarterly or annual billing?</summary><p>Yes. The subscription system supports monthly, quarterly and annual agreements. Available prices and terms are confirmed in your quotation.</p></details>
        <details><summary>Will submitting the onboarding form charge us?</summary><p>No. The form creates a request for review, not an automatically activated subscription or payment.</p></details>
        <details><summary>What happens to our records when a subscription expires?</summary><p>Access is subject to the applicable subscription and grace-period rules. Business records are not automatically deleted because a renewal becomes overdue; export and retention procedures follow the agreed policies.</p></details>
    </div></div>
</section>
@include('marketing.partials.cta')
@endsection