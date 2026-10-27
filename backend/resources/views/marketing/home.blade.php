@extends('layouts.marketing')
@section('title', 'Export and business operations, connected')
@section('description', 'Boosted One is a Ugandan ERP for exporters and growing businesses, connecting sourcing, stock, packing, cartons, shipments, documents, sales and finance.')
@section('content')

<section class="hero hero-home">
    <div class="hero-copy">
        <div class="eyebrow"><span class="eyebrow-dot"></span> EXPORT-FIRST BUSINESS MANAGEMENT · UGANDA</div>
        <h1>Every export has a story. <em>Keep the whole story connected.</em></h1>
        <p class="hero-lead">From your first purchase to the cartons you ship, Boosted One brings your procurement, warehouse, documents, customers and business finances together in one ERP.</p>
        <div class="action-row">
            <a class="button button-primary" href="{{ route('site.onboarding') }}">Request onboarding <span aria-hidden="true">↗</span></a>
            <a class="button button-outline" href="{{ route('site.features') }}">Explore what it does <span aria-hidden="true">→</span></a>
        </div>
        <div class="hero-support"><span class="support-check" aria-hidden="true">✓</span> Built for Ugandan exporters <span class="support-check" aria-hidden="true">✓</span> Flexible for growing businesses</div>
    </div>
    <div class="workspace-preview" aria-label="Illustration of connected business stages">
        <div class="preview-top"><span class="preview-identity"><span class="mini-brand" aria-hidden="true">B1</span> Boosted One <small>/ export workflow</small></span><span class="preview-status">Connected records</span></div>
        <div class="preview-title"><span class="mini-eyebrow">ONE OPERATIONAL JOURNEY</span><strong>From purchase to shipment</strong><p>Follow the same goods through each stage.</p></div>
        <div class="preview-steps">
            <div><span class="step-marker">01</span><div><strong>Supplier & purchase</strong><small>Record the source and buying terms</small></div><span aria-hidden="true">↗</span></div>
            <div><span class="step-marker">02</span><div><strong>Goods received</strong><small>Accepted stock and traceable batches</small></div><span aria-hidden="true">↗</span></div>
            <div><span class="step-marker">03</span><div><strong>Packing & cartons</strong><small>Weight, batch allocations and QR labels</small></div><span aria-hidden="true">↗</span></div>
            <div><span class="step-marker">04</span><div><strong>Export shipment</strong><small>Buyer, destination and documents</small></div><span aria-hidden="true">↗</span></div>
            <div><span class="step-marker">05</span><div><strong>Financial review</strong><small>Invoices, costs and reconciliation</small></div><span aria-hidden="true">✓</span></div>
        </div>
        <div class="preview-bottom"><span class="preview-mini-line" aria-hidden="true"></span><span>Designed for real warehouse and export teams</span></div>
    </div>
</section>

<section class="section-container intro-ribbon">
    <div class="ribbon-label">WHY BOOSTED ONE EXISTS</div>
    <p>Export work is not one activity. <strong>It is many people, documents and stock movements depending on one another.</strong> We built Boosted One to make those connections easier to manage—from market buying to the last carton loaded for dispatch.</p>
</section>

<section class="section-container section-space">
    <div class="section-heading"><div><span class="eyebrow">BUILT AROUND THE WORK</span><h2>The important parts of your business should speak to each other.</h2></div><a class="text-link" href="{{ route('site.features') }}">See all feature areas <span aria-hidden="true">↗</span></a></div>
    <div class="story-grid">
        <article class="story-card story-card-dark">
            <span class="story-number">01 / SOURCE</span>
            <h3>Know what you bought and where it came from.</h3>
            <p>Supplier offers, market purchases and incoming stock belong in the same story. Record what was requested, purchased and actually received—so downstream teams start from the right quantities.</p>
            <a href="{{ route('site.feature', 'procurement-and-sourcing') }}">Explore sourcing and procurement <span aria-hidden="true">↗</span></a>
        </article>
        <article class="story-card">
            <span class="story-number">02 / PREPARE</span>
            <h3>Understand every batch and packed carton.</h3>
            <p>Track accepted stock, rejected goods, sorting losses and packing allocations. Generate carton identities and labels from real source records rather than separate handwritten lists.</p>
            <a href="{{ route('site.feature', 'packing-and-cartons') }}">Explore packing and cartons <span aria-hidden="true">↗</span></a>
        </article>
        <article class="story-card">
            <span class="story-number">03 / DELIVER</span>
            <h3>Connect buyer orders with shipment paperwork.</h3>
            <p>Allocate prepared cartons to a shipment, maintain route and flight references, issue export documents and let the finance team work from approved commercial records.</p>
            <a href="{{ route('site.feature', 'export-shipments') }}">Explore shipment management <span aria-hidden="true">↗</span></a>
        </article>
    </div>
</section>

<section class="wide-band">
    <div class="section-container wide-band-inner">
        <div><span class="eyebrow eyebrow-light">A PLATFORM WITH A PURPOSE</span><h2>Export businesses first. Useful across everyday operations.</h2><p>Our starting point is the Ugandan exporter. The same connected approach is valuable for agribusinesses, packhouses, wholesalers and distributors with suppliers, inventory and customers to manage.</p><a class="button button-light" href="{{ route('site.solutions') }}">Explore business solutions <span aria-hidden="true">↗</span></a></div>
        <div class="band-illustration">
            <div><span>EXPORTERS</span><strong>Source → Pack → Ship</strong><small>Know where each carton fits.</small></div>
            <div><span>PROCESSORS</span><strong>Receive → Sort → Prepare</strong><small>Keep handling records clear.</small></div>
            <div><span>DISTRIBUTORS</span><strong>Buy → Stock → Sell</strong><small>Connect supply with orders.</small></div>
        </div>
    </div>
</section>

<section class="section-container section-space">
    <div class="section-heading"><div><span class="eyebrow">ONE SYSTEM, DIFFERENT STARTING POINTS</span><h2>Take on the modules your business actually needs.</h2><p class="section-intro">Some businesses begin with purchasing and stock. Others need full packing, export documents, accounting approvals and multi-role operations. We work out the right starting point during onboarding.</p></div></div>
    <div class="mini-feature-grid">
        @foreach(['receiving-and-stock','documents-and-labels','sales-and-invoicing','finance-and-reconciliation'] as $slug)
        @php $item = $featured[$slug]; @endphp
        <a class="mini-feature" href="{{ route('site.feature', $slug) }}"><span class="mini-eyebrow">{{ $item['category'] }}</span><strong>{{ $item['title'] }}</strong><p>{{ $item['tagline'] }}</p><span class="arrow-link" aria-hidden="true">↗</span></a>
        @endforeach
    </div>
</section>

<section class="section-container section-space small-top">
    <div class="next-step-panel">
        <div><span class="eyebrow">READY TO GET ORGANISED?</span><h2>Tell us how your operation works today.</h2><p>We will recommend an appropriate setup, explain the subscription options and guide your team through an approved onboarding process.</p></div>
        <div class="next-actions"><a class="button button-primary" href="{{ route('site.onboarding') }}">Request onboarding <span aria-hidden="true">↗</span></a><a href="{{ route('site.workflow') }}">See how onboarding works →</a></div>
    </div>
</section>
@endsection
