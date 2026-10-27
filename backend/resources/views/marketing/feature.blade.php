@extends('layouts.marketing')
@section('title', $feature['title'])
@section('description', $feature['tagline'].' Boosted One provides connected workflows for Ugandan exporters and growing businesses.')
@section('content')
<section class="page-hero section-container feature-detail-head">
    <div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><a href="{{ route('site.features') }}">Features</a><span aria-hidden="true">/</span><span>{{ $feature['title'] }}</span></div>
    <span class="eyebrow">{{ strtoupper($feature['category']) }}</span>
    <h1>{{ $feature['title'] }}</h1>
    <p class="page-lead">{{ $feature['tagline'] }}</p>
    <div class="action-row"><a class="button button-primary" href="{{ route('site.onboarding') }}">Discuss this module <span aria-hidden="true">↗</span></a><a class="button button-outline" href="{{ route('site.features') }}">All features <span aria-hidden="true">→</span></a></div>
</section>
<section class="section-container feature-detail-intro">
    <div><span class="eyebrow">WHAT IT DOES</span><h2>Built for the way the work actually happens.</h2><p class="body-large">{{ $feature['introduction'] }}</p></div>
    <aside class="insight-aside"><span class="mini-eyebrow">THE BUSINESS CHALLENGE</span><p>{{ $feature['challenge'] }}</p></aside>
</section>
<section class="subtle-section">
    <div class="section-container section-space">
        <div class="section-heading"><div><span class="eyebrow">WORKFLOW</span><h2>How this works in practice</h2><p class="section-intro">A practical sequence your team can follow, with each stage connected to the relevant business record.</p></div></div>
        <div class="step-grid">
            @foreach($feature['steps'] as $index => $step)
            <article class="step-card"><span class="step-label">STEP {{ str_pad($index+1, 2, '0', STR_PAD_LEFT) }}</span><h3>{{ $step['title'] }}</h3><p>{{ $step['body'] }}</p></article>
            @endforeach
        </div>
    </div>
</section>
<section class="section-container section-space">
    <div class="section-heading"><div><span class="eyebrow">CAPABILITIES</span><h2>What your business can manage</h2><p class="section-intro">More than buttons on a screen. These are the operational records and controls that make the feature useful from one day to the next.</p></div></div>
    <div class="capability-grid">
        @foreach($feature['capabilities'] as $index => $capability)
        <article class="capability"><span class="capability-number">0{{ $index+1 }}</span><div><h3>{{ $capability['title'] }}</h3><p>{{ $capability['body'] }}</p></div></article>
        @endforeach
    </div>
</section>
<section class="section-container section-space small-top">
    <div class="example-panel"><div><span class="eyebrow eyebrow-light">A PRACTICAL EXAMPLE</span><h2>{{ $feature['scenario_title'] }}</h2></div><div><p>{{ $feature['scenario'] }}</p><a class="button button-light" href="{{ route('site.onboarding') }}">Talk to our team <span aria-hidden="true">↗</span></a></div></div>
    @if(!empty($feature['notice']))<p class="capability-note"><strong>Important:</strong> {{ $feature['notice'] }}</p>@endif
</section>
<section class="section-container section-space small-top">
    <div class="section-heading"><div><span class="eyebrow">CONNECT THE NEXT STAGE</span><h2>Other modules that work alongside this one</h2></div></div>
    <div class="related-grid">
    @foreach($feature['related'] as $relatedSlug)
        @php $related = $features[$relatedSlug]; @endphp
        <a class="related-card" href="{{ route('site.feature', $relatedSlug) }}"><span class="mini-eyebrow">{{ $related['category'] }}</span><strong>{{ $related['title'] }}</strong><p>{{ $related['tagline'] }}</p><span class="arrow-link" aria-hidden="true">↗</span></a>
    @endforeach
    </div>
    <p class="muted-note">Available modules and permissions depend on your business setup and subscription agreement.</p>
</section>
@include('marketing.partials.cta')
@endsection