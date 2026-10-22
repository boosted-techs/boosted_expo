@extends('layouts.console')
@section('title','Provider Updates')
@section('content')
<div class="page-intro"><div><span class="eyebrow">EXTERNAL INTEGRATIONS</span><h1>Provider updates</h1>
<p>Review independently provisioned payment and cargo carrier notifications. Provider updates are evidence; they do not send money, post invoices or automatically change shipment status.</p></div></div>
<div class="notice"><span>●</span><div><strong>No bank or carrier is automatically connected</strong>
<p>A platform operator must verify the provider relationship and provision its actual signing secret outside the company dashboard. A validated HMAC authenticates the configured sender, but is not a bank-issued statement signature or proof of settlement. Official source verification and reconciliation remain separate.</p></div></div>
<section class="section-card"><h2>Registered provider connections</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Provider</th><th>Provider account</th><th>State</th><th>Onboarding reference</th></tr></thead><tbody>
@forelse($connections as $connection)
<tr><td>{{ ucfirst($connection->kind) }}</td><td>{{ $connection->provider_code }}</td>
<td>{{ $connection->provider_account }}</td><td>{{ $connection->active?'Enabled for signed callbacks':'Disabled' }}</td>
<td>{{ $connection->trust_reference }}</td></tr>
@empty<tr><td colspan="5" class="empty-msg">No verified provider accounts provisioned. Manual banking, shipment and documentation workflows remain available.</td></tr>
@endforelse
</tbody></table></div></section>
<section class="section-card"><h2>Recent external notifications</h2>
<p class="muted">Accepted evidence remains read-only and separate from independently approved financial journals or manually recorded airline movements. Unmatched references need investigation, not automatic linking.</p>
<div class="table-wrap"><table class="data-table">
<thead><tr><th>Received</th><th>Source</th><th>External reference</th><th>Event</th><th>Status</th><th>Review</th></tr></thead><tbody>
@forelse($events as $event)
<tr><td>{{ $event->created_at->format('d M Y H:i') }}</td>
<td>{{ ucfirst($event->kind) }}</td>
<td>{{ $event->external_reference }} <small>{{ $event->kind==='payment'?$event->currency.' '.$event->amount:'' }}</small></td>
<td>{{ str_replace('_',' ',ucfirst($event->event_type)) }}<small>Provider ID: {{ $event->provider_event_id }}</small></td>
<td>{{ str_replace('_',' ',ucfirst($event->review_status)) }}</td>
<td>
@if($canReview && in_array($event->review_status,['needs_review','unmatched'],true))
@foreach(['accept'=>'Accept evidence','reject'=>'Reject'] as $action=>$label)
<form method="POST" action="{{ route('integrations.events.review',['event'=>$event->id,'action'=>$action]) }}" class="form-stack">@csrf
<label class="field"><span>Review explanation</span><input name="note" required minlength="20" maxlength="2000" placeholder="Compare actual provider source and matching company record"></label>
<button class="secondary-button">{{ $label }}</button></form>
@endforeach
@else{{ $event->review_note?:'Awaiting review' }}@endif
</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No provider messages received.</td></tr>
@endforelse
</tbody></table></div>{{ $events->links() }}
</section>
@endsection
