@extends('layouts.public')
@section('title', 'Forgot your password')
@section('content')
<span class="eyebrow">ACCOUNT RECOVERY</span>
<h1>Forgot your password?</h1>
<p class="subhead">Enter your account email to request a password reset link.</p>

<form method="POST" action="{{ route('password.email') }}" class="form-stack">
  @csrf
  <label class="field" for="recovery-email">
    <span>Email address</span>
    <input id="recovery-email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" maxlength="255" required autofocus>
  </label>
  <button class="primary-button" type="submit">Email password reset link</button>
</form>
<p class="form-hint">The link expires after 60 minutes. For your security, we do not confirm whether an email address has an account.</p>
<p class="auth-form-links"><a href="{{ route('login') }}">← Back to sign in</a></p>
@endsection
