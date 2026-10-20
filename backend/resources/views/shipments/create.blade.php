@extends('layouts.console')
@section('title','New Shipment')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SHIPMENT PLANNING</span><h1>Create shipment</h1><p>Register the buyer, route and flight details before allocating cartons.</p></div></div>
<section class="section-card form-narrow">
@if($customers->isEmpty())
<div class="notice"><span>●</span><div><strong>A customer is required</strong><p><a class="action-link" href="{{ route('customers.index') }}">Add the buyer to your customer directory</a> before creating a shipment.</p></div></div>
@endif
<form method="POST" action="{{ route('shipments.store') }}" class="form-stack">@csrf
<input type="hidden" name="creation_key" value="{{ $creationKey }}">
@php $shipment=null; @endphp
@include('shipments._form')
<div class="form-actions"><button class="primary-button" @disabled($customers->isEmpty())>Create draft shipment</button><a class="secondary-button" href="{{ route('shipments.index') }}">Cancel</a></div>
</form>
</section>
@endsection