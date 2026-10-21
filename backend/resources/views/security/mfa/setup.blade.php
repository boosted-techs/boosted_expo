@extends('layouts.public')
@section('title','Secure your account')
@section('content')
<span class="eyebrow">ACCOUNT SECURITY</span>
<h1>Secure your account</h1>
<p class="subhead">Before opening sensitive business information, link an authenticator to your account. This protects the company even if someone discovers your password.</p>
@if($qrImage)
<div style="text-align:center;padding:12px">
<img src="{{ $qrImage }}" width="210" height="210" alt="Scan using an authenticator app">
</div>
@endif
<p class="form-hint">Use an authenticator app such as Google Authenticator, Microsoft Authenticator or a compatible password manager. Scan the image above or manually enter this setup key:</p>
<p style="word-break:break-all;letter-spacing:.12em;font-size:1.08em;text-align:center">
<strong>{{ $secret }}</strong></p>
<p class="form-hint">Choose a time-based code that changes every 30 seconds. Enter the current six-digit code to finish linking your account.</p>
<form method="POST" action="{{ route('security.mfa.enrol') }}" class="form-stack">@csrf
<label class="field"><span>Current six-digit authenticator code</span>
<input name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required autofocus></label>
<button class="primary-button">Secure my account</button></form>
<form method="POST" action="{{ route('logout') }}" style="margin-top:18px">@csrf
<button class="secondary-button">Sign out</button></form>
@endsection