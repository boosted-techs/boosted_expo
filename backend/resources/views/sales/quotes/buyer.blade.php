@extends('layouts.public')
@section('title','Your quotation')
@section('content')
<div class="eyebrow">{{ $business->name }}</div><h1>Quotation {{ $quote->reference }}</h1><p>Revision {{ $quote->revision }} · {{ $quote->currency }} {{ $quote->grand_total }}</p><p>For {{ $access->recipient_email }} · Link expires {{ $access->expires_at->format('d M Y H:i T') }}.</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<p><a class="secondary-button" href="{{ route('buyer-quotation.document',$token) }}" target="_blank" rel="noopener noreferrer">Read the issued quotation and terms</a></p>
@if($access->decision)<h2>{{ ucfirst($access->decision) }}</h2><p>{{ $access->buyer_name }} · {{ $access->buyer_reference }} · {{ $access->decided_at->format('d M Y H:i T') }}</p>
@else
<p>Read the issued quotation before recording your decision. The link records your stated response and reference.</p>
<form method="POST" action="{{ route('buyer-quotation.decide',$token) }}" class="form-stack">@csrf
<label class="field"><span>Your name</span><input name="buyer_name" required minlength="2" maxlength="120" value="{{ old('buyer_name') }}"></label>
<label class="field"><span>Purchase order / decision reference</span><input name="buyer_reference" required minlength="5" maxlength="160" value="{{ old('buyer_reference') }}"></label>
<label class="field"><span>Decision for this revision</span><select name="decision" required><option value="">Choose</option><option value="accepted">Accept quotation</option><option value="rejected">Decline quotation</option></select></label>
<button class="primary-button">Record my decision</button></form>
@endif
@endsection
