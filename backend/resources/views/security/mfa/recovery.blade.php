@extends('layouts.public')
@section('title','Save recovery codes')
@section('content')
<span class="eyebrow">ACCOUNT SECURITY</span>
<h1>Save your recovery codes</h1>
<p class="subhead">Your authenticator is now linked. Keep these ten codes somewhere private, separate from your normal password. Each code works only once if you lose access to your authenticator.</p>
<div class="section-card">
@foreach($codes as $code)
<div class="record"><strong>{{ $code }}</strong></div>
@endforeach
</div>
<p class="form-hint">For your protection the codes will not be displayed again. Store them in a password manager or another secure offline location. Do not send them by chat or email.</p>
<a href="{{ route('workspace') }}" class="primary-button">I've saved my codes — open workspace</a>
@endsection