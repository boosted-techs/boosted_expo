@extends('layouts.console')
@section('title', $activityTitle)
@section('content')
<div class="page-intro"><div><span class="eyebrow">STAFF OPERATIONS</span><h1>{{ $activityTitle }}</h1><p>{{ $activityDescription }}</p></div><a href="{{ route('people.index') }}" class="secondary-button">People & work</a></div>
@include('staff._'.$activity)
@endsection
