@extends('layouts.public')
@section('title', 'Sign in')
@section('content')
<span class="eyebrow">WELCOME BACK</span>
<h1>Sign in</h1>
<p class="subhead">Access your company's Boosted One workspace.</p>

<form method="POST" action="{{ route('login.store') }}" class="form-stack">
  @csrf
  <label class="field">
    <span>Email address</span>
    <input name="email" type="email" autocomplete="username" required value="{{ old('email') }}" maxlength="255" autofocus>
  </label>

  <div class="field">
    <label for="login-password" class="field-title">Password</label>
    <div class="password-input">
      <input id="login-password" name="password" type="password" autocomplete="current-password" required>
      <button type="button" class="password-toggle" aria-controls="login-password" aria-pressed="false" aria-label="Show password" data-password-toggle>Show</button>
    </div>
    <div class="auth-form-links"><a href="{{ route('password.request') }}">Forgot your password?</a></div>
  </div>

  <button class="primary-button" type="submit">Sign in</button>
</form>

<p class="form-hint">Company accounts are created by Boosted One, and team members join by invitation. Public registration is not available.</p>

<script defer src="{{ asset('assets/password-visibility.js') }}"></script>
@endsection
