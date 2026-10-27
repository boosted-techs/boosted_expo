@extends('layouts.platform')
@inject('platformAccess','App\Services\PlatformAccess')
@section('title','Limited support view')
@section('content')
<div class="platform-warning"><strong>Read-only support view: {{ $business->name }} · {{ $business->code }}</strong><p>Owner approved {{ $grant->scope }} for operator {{ auth()->user()->name }}. Expires {{ $grant->expires_at->timezone('Africa/Kampala')->format('d M Y H:i') }} EAT. Every access is audited. You remain signed in as yourself.</p></div><p>{{ $grant->reason }}</p>
<section class="section-card"><h2>Order workflow status</h2>@foreach($orders as $order)<div class="record"><strong>{{ $order->reference }}</strong><span>{{ $order->status }}</span></div>@endforeach{{ $orders->links() }}</section><section class="section-card"><h2>Shipment workflow status</h2>@foreach($shipments as $shipment)<div class="record"><strong>{{ $shipment->reference }}</strong><span>{{ $shipment->status }}</span></div>@endforeach{{ $shipments->links() }}</section><section class="section-card"><h2>Printer issues</h2>@foreach($printJobs as $job)<div class="record"><strong>Job #{{ $job->id }} · {{ $job->status }}</strong><span>{{ $job->failure_note }}</span></div>@endforeach{{ $printJobs->links() }}</section>
@endsection
