@extends('layouts.platform')
@inject('platformAccess','App\Services\PlatformAccess')
@section('title','Manage business')
@section('content')
<div class="page-intro"><div><span class="eyebrow">{{ $business->code }}</span><h1>{{ $business->name }}</h1><p>{{ ucfirst($business->lifecycle_status) }} · {{ $business->security_suspended_at?'Security suspended':'Access enabled' }} · Subscription {{ str_replace('_',' ',$state['state']) }}</p></div></div>
<nav class="platform-tabs" aria-label="Business record">@foreach(['overview'=>'Overview','subscription'=>'Subscription','usage'=>'Usage','access'=>'Access','health'=>'Health','history'=>'History'] as $key=>$label)@if(($key!=='subscription'||$platformAccess->allows(auth()->user(),'billing'))&&($key!=='access'||$platformAccess->allows(auth()->user(),'business'))&&($key!=='history'||$platformAccess->allows(auth()->user(),'audit')))<a href="{{ route('platform.businesses.show',[$business->id,'tab'=>$key]) }}" @if($key===$tab) aria-current="page" @endif>{{ $label }}</a>@endif @endforeach</nav>
@include('platform._business-'.$tab)
@endsection
