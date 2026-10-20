@extends('layouts.public')
@section('title', 'Choose a company')
@section('content')
<span class="eyebrow">YOUR COMPANIES</span>
<h1>Choose a workspace</h1>
<p class="subhead">Select the exporting business you want to work with.</p>
@if($memberships->isEmpty())
  <p class="notice-message">There are no active companies for your account. Contact your business administrator to request access.</p>
@else
  <div class="choice-list">
    @foreach($memberships as $membership)
      <form method="POST" action="{{ route('businesses.activate') }}">@csrf
        <input type="hidden" name="business_id" value="{{ $membership->business_id }}">
        <button class="choice-button" type="submit"><strong>{{ $membership->business->name }}</strong><span>{{ ucfirst($membership->role) }} · {{ $membership->business->country_code }} →</span></button>
      </form>
    @endforeach
  </div>
@endif
<form method="POST" action="{{ route('logout') }}" class="subtle-form">@csrf<button class="text-button">Sign out</button></form>
@endsection