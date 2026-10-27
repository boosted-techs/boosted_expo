@extends('layouts.marketing')
@section('title', 'ERP features for export and business operations')
@section('description', 'Explore Boosted One ERP features in detail: procurement, goods receiving, batch and carton traceability, packing, Zebra labels, export shipments, sales and financial controls.')
@section('content')
<section class="page-hero section-container">
    <div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><span>Features</span></div>
    <span class="eyebrow">DETAILED FEATURE AREAS</span>
    <h1>One connected system. <em>Many important jobs.</em></h1>
    <p class="page-lead">Boosted One was developed to connect export operations that normally get split between books, spreadsheets and different departments. Explore each area to understand how the workflow works, what your team can record and how it connects to the next stage.</p>
</section>
<section class="section-container feature-index">
    <div class="index-intro"><h2>Built around the export journey</h2><p>A shipment starts with sourcing, not with a flight. The product is most valuable when what happens during procurement, receiving, packing and customer fulfilment stays connected. Each feature below has a dedicated page with practical business examples.</p></div>
    <div class="feature-card-grid">
    @foreach(['procurement-and-sourcing', 'receiving-and-stock', 'packing-and-cartons', 'export-shipments', 'documents-and-labels', 'sales-and-invoicing', 'finance-and-reconciliation', 'traceability-and-quality', 'teams-and-operations'] as $index => $slug)
        @php $item = $features[$slug]; @endphp
        <article class="feature-index-card">
            <div class="feature-card-top"><span class="feature-number">{{ str_pad($index+1, 2, '0', STR_PAD_LEFT) }}</span><span class="pill">{{ $item['category'] }}</span></div>
            <h3><a href="{{ route('site.feature', $slug) }}">{{ $item['title'] }}</a></h3>
            <p>{{ $item['introduction'] }}</p>
            <a class="text-link" href="{{ route('site.feature', $slug) }}">Understand this feature <span aria-hidden="true">↗</span></a>
        </article>
    @endforeach
    </div>
</section>
<section class="section-container section-space">
    <div class="split-insight"><div><span class="eyebrow">WHY CONNECTED MATTERS</span><h2>More than a list of software modules.</h2></div><div><p>When an accepted stock batch feeds a packing job and a packed carton feeds an export shipment, the business can answer real operational questions. Which goods were used? What was rejected? What was dispatched? Which customer order did those cartons fulfil?</p><p>Boosted One is designed to make those relationships visible to authorised staff, without forcing teams to maintain the same information in several separate records.</p><a class="text-link" href="{{ route('site.workflow') }}">See the complete workflow →</a></div></div>
</section>
@include('marketing.partials.cta')
@endsection