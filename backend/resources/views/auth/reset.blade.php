@extends('layouts.public')
@section('title', 'Set a new password')
@section('content')
<span class="eyebrow">ACCOUNT RECOVERY</span>
<h1>Set a new password</h1>
<p class="subhead">Choose a new password for your Boosted One account. Use at least 12 characters. This link can be used only once.</p>

<form method="POST" action="{{ route('password.update') }}" class="form-stack">
  @csrf
  <input type="hidden" name="token" value="{{ $token }}">
  <label class="field" for="reset-email">
    <span>Account email</span>
    <input id="reset-email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required readonly>
  </label>

  <div class="field">
    <label for="new-password" class="field-title">New password</label>
    <div class="password-input">
      <input id="new-password" name="password" type="password" minlength="12" maxlength="255" autocomplete="new-password" required autofocus>
      <button type="button" class="password-toggle" aria-controls="new-password" aria-pressed="false" aria-label="Show new password" data-password-toggle>Show</button>
    </div>
  </div>

  <div class="field">
    <label for="confirm-password" class="field-title">Confirm new password</label>
    <div class="password-input">
      <input id="confirm-password" name="password_confirmation" type="password" minlength="12" maxlength="255" autocomplete="new-password" required>
      <button type="button" class="password-toggle" aria-controls="confirm-password" aria-pressed="false" aria-label="Show confirm new password" data-password-toggle>Show</button>
    </div>
  </div>
  <button type="submit" class="primary-button">Update password</button>
</form>
<p class="form-hint">If this link has expired or you already used it, <a href="{{ route('password.request') }}">request a new password reset link</a>.</p>

<script defer src="{{ asset('assets/password-visibility.js') }}"></script>
@endsection
