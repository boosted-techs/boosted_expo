@extends('layouts.console')
@section('title','Approve quantity-only work')
@section('content')
@if($canManage&&!$record->order)<section class="section-card"><h2>Approve quantity-only work</h2><p>Approve sourcing while pricing is pending.</p><form method="POST" action="{{ route('sales-requests.approve',$record) }}" class="form-stack">@csrf<input name="reason" required minlength="10" maxlength="1000" placeholder="Reason for approval"><button class="primary-button">Approve sourcing</button></form></section>@endif

@endsection