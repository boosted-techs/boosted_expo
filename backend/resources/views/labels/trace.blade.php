@extends('layouts.console')
@section('title','Private traceability')
@section('content')
<div class="page-intro"><div><h1>{{ $title }}</h1><p>Private business traceability. This scan has not published the record.</p></div></div>
<section class="section-card">@foreach($fields as $name=>$value)<div class="record"><strong>{{ $name }}</strong><span>{{ $value ?: 'Not recorded' }}</span></div>@endforeach</section>
@endsection
