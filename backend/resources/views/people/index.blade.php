@extends('layouts.console')
@section('title', $activityTitle)
@section('content')
<div class="page-intro"><div><span class="eyebrow">PEOPLE & WORK</span><h1>{{ $activityTitle }}</h1><p>{{ $activityDescription }}</p></div><a href="{{ route('staff.index') }}" class="secondary-button">My clock-in / clock-out</a></div>
@if($activity==='overview')
<div class="action-grid">
@foreach($activities as $key=>$item)
@if(!in_array($key,['register','rota-create','attendance-record']) && ($manager || in_array($key,['weekly','attendance','tasks'])))<a class="activity-card" href="{{ route('people.activity',$key) }}"><x-ui-icon name="people" /><h2>{{ $item[0] }}</h2><p>{{ $item[1] }}</p><span class="action-link">Open <x-ui-icon name="arrow" /></span></a>@endif
@endforeach
@if($manager)<a class="activity-card" href="{{ route('team.index') }}"><x-ui-icon name="settings" /><h2>System access</h2><p>Manage logins, invitations and access history.</p><span class="action-link">Open <x-ui-icon name="arrow" /></span></a>@endif
</div>
@else
@include('people._'.$activity)
@endif
@endsection
