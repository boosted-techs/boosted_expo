@extends('layouts.marketing')
@section('title', $solution['title'])
@section('description', $solution['tagline'].' Learn how Boosted One connects relevant operations for Ugandan businesses.')
@section('content')
<section class="page-hero section-container">
    <div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><a href="{{ route('site.solutions') }}">Solutions</a><span aria-hidden="true">/</span><span>{{ $solution['title'] }}</span></div>
    <span class="eyebrow">{{ strtoupper($solution['category']) }}</span>
    <h1>{{ $solution['title'] }}</h1>
    <p class="page-lead">{{ $solution['intro'] }}</p>
    <div class="action-row"><a class="button button-primary" href="{{ route('site.onboarding') }}">Discuss your operation ↗</a><a class="button button-outline" href="{{ route('site.features') }}">Explore features →</a></div>
</section>
<section class="subtle-section"><div class="section-container section-space"><div class="section-heading"><div><span class="eyebrow">WHO IT HELPS</span><h2>A practical fit for your team</h2><p class="section-intro">{{ $solution['audience'] }}</p></div></div><div class="section-heading tighter"><div><span class="eyebrow">A CONNECTED JOURNEY</span><h2>{{ $solution['story_title'] }}</h2></div></div><div class="timeline">
    @foreach($solution['story'] as $index => $step)
    <article class="timeline-item"><span class="timeline-step">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span><div><span class="mini-eyebrow">{{ $step['stage'] }}</span><h3>{{ $step['title'] }}</h3><p>{{ $step['body'] }}</p></div></article>
    @endforeach
</div></div></section>
<section class="section-container section-space"><div class="split-insight"><div><span class="eyebrow">WHY IT MATTERS</span><h2>Keep a clearer record from start to finish.</h2></div><div><p>{{ $solution['why'] }}</p>@if(!empty($solution['caution']))<p class="capability-note">{{ $solution['caution'] }}</p>@endif</div></div></section>
<section class="section-container section-space small-top"><div class="section-heading"><div><span class="eyebrow">RELEVANT MODULES</span><h2>Explore the workflows behind this solution</h2></div></div><div class="related-grid">@foreach($solution['features'] as $featureSlug)@php $f = $features[$featureSlug]; @endphp<a class="related-card" href="{{ route('site.feature', $featureSlug) }}"><span class="mini-eyebrow">{{ $f['category'] }}</span><strong>{{ $f['title'] }}</strong><p>{{ $f['tagline'] }}</p><span class="arrow-link" aria-hidden="true">↗</span></a>@endforeach</div></section>
@include('marketing.partials.cta')
@endsection