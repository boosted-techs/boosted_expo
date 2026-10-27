@extends('layouts.marketing')
@section('title', 'Contact Boosted One')
@section('description', 'Contact Boosted One ERP by Boosted Technologies Limited for Uganda export-business demos, onboarding questions, subscriptions and operational support.')
@section('content')
<section class="page-hero section-container">
    <div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><span>Contact</span></div>
    <span class="eyebrow">LET'S TALK ABOUT YOUR OPERATIONS</span>
    <h1>Questions about your business? <em>Talk to us.</em></h1>
    <p class="page-lead">Whether you are preparing your first export or managing a growing operation, we can help you discuss the right ERP modules, onboarding process and subscription arrangement.</p>
</section>
<section class="section-container contact-layout">
    <div class="contact-card contact-primary"><span class="mini-eyebrow">NEW BUSINESS & SUBSCRIPTIONS</span><h2>Start with an onboarding request</h2><p>Tell us about your suppliers, warehouse, packing, customers or shipping processes. An onboarding request gives us the details needed to discuss a suitable setup without creating an account or collecting payment.</p><a class="button button-primary" href="{{ route('site.onboarding') }}">Request onboarding ↗</a></div>
    <div class="contact-card"><span class="mini-eyebrow">GENERAL ENQUIRIES</span><h2>Email Boosted One</h2><p>For questions about the platform, billing arrangements or account support, email us from an address your team can use for follow-up.</p><a class="text-link" href="mailto:boostedone@boostedtechs.com">boostedone@boostedtechs.com ↗</a><p class="muted-note">Please do not email passwords, one-time codes, private financial credentials or sensitive employee records.</p></div>
</section>
<section class="section-container section-space">
    <div class="split-insight"><div><span class="eyebrow">FIND AN ANSWER</span><h2>You may find what you need right here.</h2></div><div class="faq">
        <details><summary>What types of business does Boosted One serve?</summary><p>Export businesses are our first priority, especially those managing sourcing, stock, packing, cartons and overseas buyer shipments. Shared ERP functions can also help processors, wholesalers, distributors and smaller businesses.</p></details>
        <details><summary>Can I create my own account instantly?</summary><p>We use approved business onboarding. You submit a request, agree a suitable setup, and an administrator invites the initial business owner.</p></details>
        <details><summary>Where can I learn more about the modules?</summary><p>Our <a href="{{ route('site.features') }}">feature pages</a> explain each part of the operation in detail, including typical processes and relevant records.</p></details>
        <details><summary>What are the subscription options?</summary><p>We offer monthly, quarterly and annual subscription arrangements. See <a href="{{ route('site.pricing') }}">pricing and billing information</a> for the details currently published.</p></details>
    </div></div>
</section>
@endsection