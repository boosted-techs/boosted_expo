@extends('layouts.marketing')
@section('title', 'How Boosted One works')
@section('description', 'Learn how Boosted One ERP onboarding works and how buyers, warehouse staff, packing teams, exporters and accountants keep business records connected.')
@section('content')
<section class="page-hero section-container">
    <div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><span>How it works</span></div>
    <span class="eyebrow">FROM FIRST SETUP TO DAILY WORK</span>
    <h1>One business. <em>A clearer way to work together.</em></h1>
    <p class="page-lead">Boosted One follows the real movement of information through a business: from buying and receiving stock to packing, dispatch, invoicing and review. Each stage builds on records from the previous one.</p>
</section>
<section class="subtle-section">
    <div class="section-container section-space">
        <div class="section-heading"><div><span class="eyebrow">THE EXPORT WORKFLOW</span><h2>From purchase to a completed shipment</h2><p class="section-intro">This is the workflow the platform was first designed to support. Businesses can use the relevant stages without necessarily adopting every module.</p></div></div>
        <div class="workflow-journey">
            <article><span class="flow-count">01</span><div><span class="mini-eyebrow">PROCUREMENT</span><h3>Source goods with a record of the agreement</h3><p>Begin with the supplier and product. Invite offers when appropriate, agree quantities and create a purchase. The buying record becomes the starting reference for goods arriving at the warehouse.</p><a href="{{ route('site.feature', 'procurement-and-sourcing') }}">Read about procurement →</a></div></article>
            <article><span class="flow-count">02</span><div><span class="mini-eyebrow">WAREHOUSE</span><h3>Receive and inspect what actually arrived</h3><p>Record the quantities accepted and rejected, generate an identifiable source batch and keep the available stock distinct from items that fail quality checks. Receipts stay linked to the purchase.</p><a href="{{ route('site.feature', 'receiving-and-stock') }}">Read about inventory →</a></div></article>
            <article><span class="flow-count">03</span><div><span class="mini-eyebrow">PACKING</span><h3>Prepare traceable cartons and labels</h3><p>Start a packing job, record grading losses, allocate source batches into cartons and generate the corresponding sticker. The carton knows where its stock came from, even if it contains more than one source batch.</p><a href="{{ route('site.feature', 'packing-and-cartons') }}">Read about packing →</a></div></article>
            <article><span class="flow-count">04</span><div><span class="mini-eyebrow">EXPORT FULFILMENT</span><h3>Allocate identified cartons to the customer shipment</h3><p>Confirm the buyer and destination, select the packed cartons, prepare packing lists and dispatch documents, and follow any internal readiness or approval requirements before recording shipment progress.</p><a href="{{ route('site.feature', 'export-shipments') }}">Read about shipments →</a></div></article>
            <article><span class="flow-count">05</span><div><span class="mini-eyebrow">SALES AND FINANCE</span><h3>Connect commercial records and financial review</h3><p>Link sales requests, quotations and orders to fulfilment. Finance staff can review source documents, invoice handoffs, payments, stock costs and reconciliation reports through controlled workflows.</p><a href="{{ route('site.feature', 'finance-and-reconciliation') }}">Read about finance →</a></div></article>
        </div>
    </div>
</section>
<section class="section-container section-space">
    <div class="section-heading"><div><span class="eyebrow">GETTING STARTED</span><h2>Onboarding is guided, not an open registration form.</h2><p class="section-intro">A business should be ready to use the system correctly, with an approved owner, relevant modules and a clear commercial agreement.</p></div></div>
    <div class="step-grid">
        <article class="step-card"><span class="step-label">FIRST</span><h3>Tell us how your business operates</h3><p>Use our <a href="{{ route('site.onboarding') }}">onboarding request</a> to share your business type, main activities and preferred subscription cycle. No account or charge is created by submitting the form.</p></article>
        <article class="step-card"><span class="step-label">NEXT</span><h3>Agree the setup and subscription</h3><p>Our team reviews your needs, explains modules, confirms scope and issues written commercial terms and payment information.</p></article>
        <article class="step-card"><span class="step-label">THEN</span><h3>Activate the business workspace</h3><p>An authorised platform administrator creates the company workspace and invites its owner. The owner accepts the invitation and manages staff access according to the business roles.</p></article>
        <article class="step-card"><span class="step-label">GO LIVE</span><h3>Start with accurate business records</h3><p>Set up products, suppliers, customers and relevant operating settings before moving to receiving, packing, sales or export shipments. New modules can be introduced according to the agreed scope.</p></article>
    </div>
</section>
<section class="section-container section-space small-top">
    <div class="split-insight"><div><span class="eyebrow">A WORD ABOUT INTEGRATIONS</span><h2>Records remain yours to review.</h2></div><div><p>Printed QR labels, recorded dispatch statuses and financial documents support business operations, but they do not automatically confirm physical printing, airline delivery, government clearance or money settlement.</p><p>Those external outcomes require the relevant equipment, service provider or issuing authority. Boosted One keeps the internal process connected and provides controlled integration points where separately configured.</p></div></div>
</section>
@include('marketing.partials.cta')
@endsection