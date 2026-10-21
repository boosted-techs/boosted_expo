@extends('layouts.public')
@section('title','Confirm account security')
@section('content')
<span class="eyebrow">ACCOUNT SECURITY</span>
<h1>Confirm it’s you</h1>
<p class="subhead">Enter a fresh six-digit code from your authenticator app to access the export workspace.</p>
<form method="POST" action="{{ route('security.mfa.verify') }}" class="form-stack">@csrf
<label class="field"><span>Authenticator code</span>
<input name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" autofocus></label>
<label class="field"><span>Or use an unused recovery code</span>
<input name="recovery_code" type="text" autocomplete="off" maxlength="40" placeholder="Recovery code if you cannot access your authenticator"></label>
<button class="primary-button">Continue securely</button>
</form>
<p class="form-hint">Each authenticator code can be used only once. If you have lost the app and all recovery codes, contact your verified company administrator through an independent support channel.</p>
<form method="POST" action="{{ route('logout') }}" style="margin-top:18px">@csrf
<button class="secondary-button">Sign out</button></form>
@endsection