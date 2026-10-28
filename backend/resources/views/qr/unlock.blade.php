@extends('layouts.public')
@section('title','Protected product page')
@section('content')
<section class="auth-card"><x-brand-mark /><h1>Protected product page</h1><p>{{ $business->name }}</p><form method="POST" action="{{ route('qr.unlock',$qr->public_token) }}" class="form-stack">@csrf<label class="field"><span>Secret PIN</span><input type="password" name="pin" required maxlength="64" autocomplete="off" placeholder="Enter the PIN shared with you"></label>@error('pin')<p class="flash error">{{ $message }}</p>@enderror<button class="primary-button">Open product page</button></form></section>
@endsection
