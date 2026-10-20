@extends('layouts.public')
@section('title', 'Accept invitation')
@section('content')
<span class="eyebrow">COMPANY INVITATION</span>
<h1>Join {{ $invitation->business->name }}</h1>
<p class="subhead">You've been invited as {{ ucfirst($invitation->role) }} using <strong>{{ $invitation->email }}</strong>.</p>
@if($existing)
  @if(auth()->check() && auth()->user()->email === $invitation->email)
    <form method="POST" action="{{ route('invitations.accept', $token) }}" class="form-stack">@csrf
      <button class="primary-button" type="submit">Accept and enter workspace</button>
    </form>
  @elseif(auth()->check())
    <p class="notice-message">Sign out of {{ auth()->user()->email }} and sign in with {{ $invitation->email }} to accept this invitation.</p>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="secondary-button" type="submit">Sign out</button></form>
  @else
    <p>You already have an account. Sign in using the invited email address, then accept this invitation.</p>
    <a href="{{ route('login') }}" class="primary-button">Sign in to continue</a>
  @endif
@elseif(auth()->check())
  <p class="notice-message">You are signed in with another account. Please sign out before creating this invited account.</p>
  <form method="POST" action="{{ route('logout') }}">@csrf<button class="secondary-button" type="submit">Sign out</button></form>
@else
  <form method="POST" action="{{ route('invitations.accept', $token) }}" class="form-stack">@csrf
    <label class="field"><span>Your name</span><input name="name" value="{{ old('name') }}" required autocomplete="name" minlength="2"></label>
    <label class="field"><span>Create a password</span><input name="password" type="password" autocomplete="new-password" minlength="12" required><small>At least 12 characters.</small></label>
    <label class="field"><span>Confirm password</span><input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></label>
    <button class="primary-button" type="submit">Create account and join</button>
  </form>
@endif
<p class="form-hint">Invitation links are valid for three days and can be used once.</p>
@endsection