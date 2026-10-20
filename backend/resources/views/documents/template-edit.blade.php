@extends('layouts.console')
@section('title','Edit Document Template')
@section('content')
<div class="page-intro"><div><span class="eyebrow">DOCUMENT TEMPLATE</span><h1>{{ $template->name }}</h1><p>Editing this template creates a new version. Issued documents remain unchanged.</p></div></div>
<section class="section-card form-narrow">
<form method="POST" action="{{ route('document-templates.update',$template->id) }}" class="form-stack">@csrf @method('PUT')
@include('documents._template_form')
<div class="form-actions"><button class="primary-button">Save next version</button><a class="secondary-button" href="{{ route('document-templates.index') }}">Cancel</a></div>
</form></section>
@endsection