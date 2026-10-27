@extends('layouts.marketing')
@section('title', 'About Boosted One ERP')
@section('description', 'Learn why Boosted Technologies developed Boosted One around Ugandan export operations, product traceability and connected internal business management.')
@section('content')
<section class="page-hero section-container">
    <div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><span>About</span></div>
    <span class="eyebrow">A PRODUCT OF BOOSTED TECHNOLOGIES</span>
    <h1>Business software built around <em>real operational work.</em></h1>
    <p class="page-lead">Boosted One began with a practical export problem: how do you manage buying, incoming produce, packing, labelled cartons, overseas shipments and internal accounts without losing the relationship between them?</p>
</section>
<section class="section-container section-space">
    <div class="split-insight"><div><span class="eyebrow">OUR REASON FOR BUILDING IT</span><h2>Exporting is a chain of decisions, not a single form.</h2></div><div><p>For businesses moving products from local suppliers to international buyers, useful records start before the cargo reaches a freight handler. Sourcing conditions, receipt quality, packed weights, carton identifiers and customer commitments all affect what happens later.</p><p>We developed Boosted One to connect those steps in one business workspace. Instead of treating export documentation as separate from daily internal operations, the platform follows the goods and the people responsible for them.</p></div></div>
</section>
<section class="subtle-section"><div class="section-container section-space">
    <div class="section-heading"><div><span class="eyebrow">WHAT WE PRIORITISE</span><h2>Practical tools that make records meaningful</h2></div></div>
    <div class="principles-grid">
        <article><span class="principle-index">01</span><h3>Export first</h3><p>The primary design centre is the Ugandan exporter, with particular attention to sourcing, goods receiving, quality, packing, cartons, shipments and export-related documents.</p></article>
        <article><span class="principle-index">02</span><h3>Connected internal operations</h3><p>Buyers, warehouse teams, logistics, sales and accountants need information from one another. Boosted One makes that context part of the normal workflow.</p></article>
        <article><span class="principle-index">03</span><h3>Accessible growth</h3><p>Not every company starts with the same size or needs. The platform is intended to support a suitable starting set of modules and deeper controls as operations become more complex.</p></article>
        <article><span class="principle-index">04</span><h3>Responsible records</h3><p>Clear permissions, audit trails and honest descriptions of external integrations matter. A printed code is not a certification, and a recorded dispatch is not the same as confirmed airline delivery.</p></article>
    </div>
</div></section>
<section class="section-container section-space">
    <div class="about-highlight"><div><span class="eyebrow">ABOUT THE COMPANY</span><h2>Developed by Boosted Technologies Limited</h2><p>Boosted Technologies builds technology products that address practical business and community needs. Boosted One is its ERP product focused on exporters and the connected work that keeps their businesses operating.</p><a class="text-link" href="{{ route('site.contact') }}">Contact the Boosted One team →</a></div><div class="about-badge"><strong>B1</strong><span>BOOSTED ONE<br>BUSINESS ERP · UGANDA</span></div></div>
</section>
@include('marketing.partials.cta')
@endsection