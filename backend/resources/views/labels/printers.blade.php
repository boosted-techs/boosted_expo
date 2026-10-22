@extends('layouts.console')
@section('title','Print Workstations')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ZEBRA PRINTING</span><h1>Print workstations</h1>
<p>Queue original Zebra label files for an authorised workstation on your own local network.</p></div></div>
<div class="notice"><span>●</span><div><strong>Server-side printing is disabled</strong>
<p>Your workstation runs a separately installed, opt-in print agent and opens its own local connection to the Zebra device. The ERP does not contact arbitrary printer IP addresses. “Transferred” means the workstation reported sending the bytes to the printer socket, not that a physical sticker was successfully printed.</p></div></div>
@if(session('new_agent_token') && session('new_agent_id'))
<section class="section-card">
<h2>Save this workstation secret now</h2>
<p>This token is shown only after registration. Save it privately on the designated printing machine, not in your company website or a shared message. It cannot be viewed again.</p>
<div class="record"><strong>Agent identifier</strong><span>{{ session('new_agent_id') }}</span></div>
<div class="record"><strong>One-time device token</strong><code style="overflow-wrap:anywhere">{{ session('new_agent_token') }}</code></div>
</section>
@endif
<section class="section-card"><h2>Registered workstations</h2>
@if($canManage)
<form method="POST" action="{{ route('labels.printers.create') }}" class="form-stack form-narrow">@csrf
<label class="field"><span>Workstation name</span><input name="name" maxlength="100" minlength="3" required placeholder="Packing floor Zebra ZT230"></label>
<button class="primary-button">Register local print workstation</button></form>
@endif
<div class="table-wrap"><table class="data-table"><thead><tr><th>Workstation</th><th>Connected last</th><th>Status</th>@if($canManage)<th>Access</th>@endif</tr></thead><tbody>
@forelse($agents as $agent)<tr><td><strong>{{ $agent->name }}</strong>
<small>Device #{{ $agent->id }}</small></td>
<td>{{ $agent->last_seen_at?->format('d M Y H:i')?:'Not connected' }}</td>
<td>{{ $agent->active?'Authorised':'Revoked' }}</td>
@if($canManage)<td>@if($agent->active)
<form method="POST" action="{{ route('labels.printers.revoke',$agent->id) }}">@csrf
<button class="secondary-button">Revoke workstation</button></form>
@endif</td>@endif</tr>
@empty<tr><td colspan="{{ $canManage?4:3 }}" class="empty-msg">No registered workstations.</td></tr>@endforelse
</tbody></table></div></section>
@if($canPrint)
<section class="section-card form-narrow"><h2>Queue a saved label</h2>
<p>Generate a carton label first; this queue always uses that saved, fingerprinted ZPL file, not a live regenerated template.</p>
<form method="POST" action="{{ route('labels.printers.jobs') }}" class="form-stack">@csrf
<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<label class="field"><span>Printer workstation</span><select name="agent_id" required><option value="">Select a registered workstation</option>
@foreach($agents as $agent) @if($agent->active)<option value="{{ $agent->id }}">{{ $agent->name }}</option>@endif @endforeach
</select></label>
<label class="field"><span>Previously generated label</span><select name="generation_id" required>
<option value="">Select original label snapshot</option>
@foreach($generations as $generation)
<option value="{{ $generation->id }}">{{ $generation->carton?->carton_code?:'Carton' }} · Label #{{ $generation->id }} · {{ $generation->created_at->format('d M H:i') }}</option>
@endforeach</select></label>
<button class="primary-button">Queue label for workstation</button>
</form>
</section>
@endif
<section class="section-card"><h2>Delivery audit</h2>
<p class="muted">A queued label can be attempted more than once if a workstation crashes before acknowledging it. Before reprinting, confirm whether the carton is already labelled to prevent duplicates.</p>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Queued</th><th>Carton</th><th>Workstation</th><th>Attempts</th><th>Reported status</th></tr></thead><tbody>
@forelse($jobs as $job)<tr>
<td>{{ $job->created_at->format('d M Y H:i') }}</td>
<td>{{ $job->generation?->carton?->carton_code??'Unknown carton' }}</td>
<td>{{ $job->agent?->name??'Revoked workstation' }}</td>
<td>{{ $job->attempts }}</td>
<td>{{ match($job->status){
'queued'=>'Waiting for agent','leased'=>'Sent to agent; awaiting acknowledgement',
'transferred'=>'Workstation reported socket delivery; physical print unverified',
'failed'=>'Failed - inspect printer before requeue','cancelled'=>'Cancelled',
default=>$job->status} }}
@if($job->failure_note)<small>{{ $job->failure_note }}</small>@endif</td>
</tr>
@empty<tr><td colspan="5" class="empty-msg">No print deliveries recorded.</td></tr>@endforelse
</tbody></table></div>{{ $jobs->links() }}</section>
<p class="form-hint">Agent instructions: deploy/zebra_agent/README.md. Direct printing requires a supported network Zebra printer on the workstation's trusted local network. You can still download individual ZPL files from label history without installing an agent.</p>
@endsection
