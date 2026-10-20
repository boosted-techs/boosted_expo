@extends('layouts.console')
@section('title','Edit Shipment')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SHIPMENT PLANNING</span><h1>Edit {{ $shipment->reference }}</h1><p>Update this draft before confirming the carton allocation.</p></div></div>
<section class="section-card form-narrow">
<form method="POST" action="{{ route('shipments.update',$shipment->id) }}" class="form-stack">@csrf @method('PUT')
@php $business=request()->attributes->get('activeBusiness'); @endphp
@include('shipments._form')
<div class="form-actions"><button class="primary-button">Save shipment details</button><a class="secondary-button" href="{{ route('shipments.show',$shipment->id) }}">Cancel</a></div>
</form>
</section>
@endsection