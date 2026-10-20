@extends('layouts.public')
@section('title', 'Sign in')
@section('content')
<span class="eyebrow">WELCOME BACK</span>
<h1>Sign in</h1><p class="subhead">Access your company's exporting workspace.</p>
<form method="POST" action="{{ route('login.store') }}" class="form-stack">@csrf
  <label class="field"><span>Email address</span><input name="email" type="email" autocomplete="username" required value="{{ old('email') }}" maxlength="255" autofocus></label>
  <label class="field"><span>Password</span><input name="password" type="password" autocomplete="current-password" required></label>
  <button class="primary-button" type="submit">Sign in</button>
</form>
<p class="form-hint">Company accounts are created by Boosted, and employees join by invitation. Public registration is not available.</p>
@endsection