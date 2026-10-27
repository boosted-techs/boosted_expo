@extends('layouts.console')
@section('title', 'Sticker templates')
@section('content')
<div class="page-intro"><div><span class="eyebrow">STICKERS</span><h1>Sticker templates</h1><p>Use a saved template, preview the actual cartons, then print.</p></div></div>
@include('labels._nav')
<section class="section-card"><h2>Saved templates</h2>
@forelse($templates as $entry)<div class="record"><div><strong>{{ $entry->name }}</strong><small>{{ $entry->width_mm }} × {{ $entry->height_mm }} mm · {{ $entry->dpi }} dpi · Version {{ $entry->version }} · {{ $entry->qr_visibility === 'internal' ? 'Private QR' : 'Public QR' }}{{ $entry->approved_at ? ' · Approved' : ' · Legacy — save to enable preview' }}</small></div>@if($canManage)<a class="secondary-button" href="{{ route('labels.edit',$entry->id) }}">Edit</a>@endif</div>@empty<p>No saved templates.</p>@endforelse
</section>
@if($canManage)<section class="section-card"><h2>Create template</h2><form method="POST" action="{{ route('labels.store') }}" enctype="multipart/form-data" class="form-stack">@csrf @include('labels._template-form')</form></section>@endif
@endsection
