@extends('layouts.console')
@section('title', 'Edit sticker template')
@section('content')
<div class="page-intro"><div><h1>Edit sticker template</h1><p>{{ $template->name }} · Version {{ $template->version }}</p></div></div>
<section class="section-card"><form method="POST" action="{{ route('labels.update',$template->id) }}" enctype="multipart/form-data" class="form-stack">@csrf @method('PUT') @include('labels._template-form')</form></section>
@endsection
