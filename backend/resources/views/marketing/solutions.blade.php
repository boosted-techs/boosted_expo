@extends('layouts.marketing')
@section('title', 'ERP solutions for Ugandan exporters and growing businesses')
@section('description', 'See how Boosted One supports Ugandan produce exporters, packhouses, distributors and smaller businesses with connected internal operations and shipment management.')
@section('content')
<section class="page-hero section-container">
    <div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><span>Solutions</span></div>
    <span class="eyebrow">FIND THE RIGHT WORKFLOW</span>
    <h1>Made for exporters. <em>Useful across your business.</em></h1>
    <p class="page-lead">Export operations remain our primary focus. But the day-to-day work surrounding an export—purchasing, receiving, managing people, handling orders and checking finances—also matters to many other Ugandan businesses.</p>
</section>
<section class="section-container solution-sections">
    @foreach($solutions as $slug => $solution)
    <article class="solution-row">
        <div class="solution-text"><span class="eyebrow">{{ strtoupper($solution['category']) }}</span><h2><a href="{{ route('site.solution', $slug) }}">{{ $solution['title'] }}</a></h2><p>{{ $solution['intro'] }}</p><a class="text-link" href="{{ route('site.solution', $slug) }}">Explore this business solution <span aria-hidden="true">↗</span></a></div>
        <div class="solution-visual"><span class="mini-eyebrow">TYPICAL WORKFLOW</span><h3>{{ $solution['tagline'] }}</h3><div class="solution-mini-flow">@foreach($solution['story'] as $step)<div><span>{{ $step['stage'] }}</span><strong>{{ $step['title'] }}</strong></div>@endforeach</div></div>
    </article>
    @endforeach
</section>
<section class="section-container section-space small-top">
    <div class="split-insight"><div><span class="eyebrow">NOT JUST A POINT OF SALE</span><h2>The right system for the right job.</h2></div><div><p>Boosted One focuses on connected sourcing, warehouse operations, export fulfilment and internal business management. A shop mainly needing fast counter checkout might be better suited to Boosted+ POS, a separate product from Boosted Technologies.</p><p>We can help you work out which setup matches your operational needs during onboarding.</p><a class="text-link" href="{{ route('site.onboarding') }}">Tell us about your business →</a></div></div>
</section>
@include('marketing.partials.cta')
@endsection