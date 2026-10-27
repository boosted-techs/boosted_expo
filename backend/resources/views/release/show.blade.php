@extends('layouts.console')
@section('title','Release Acceptance Review')
@section('content')
<div class="page-intro"><div><span class="eyebrow">STAGING RELEASE CHECKLIST</span><h1>{{ $run->release_label }}</h1>
<p>Every test is independently reviewed and tied to one exact artifact. Status: {{ $run->status==='sealed'?'Evidence sealed':'Acceptance in progress' }}.</p></div></div>
<div class="form-actions"><a href="{{ route('release.acceptance.index') }}" class="secondary-button">All acceptance campaigns</a></div>
<section class="section-card">
<div class="record"><strong>Candidate SHA-256</strong><code style="overflow-wrap:anywhere">{{ $run->artifact_sha256 }}</code></div>
<div class="record"><strong>Compiled frontend SHA-256</strong><code style="overflow-wrap:anywhere">{{ $run->assets_sha256??'Legacy campaign: not bound to a build' }}</code></div>
<div class="record"><strong>Test scope and environment</strong><span>{{ $run->scope_note }}</span></div>
<div class="record"><strong>Signed acceptance items</strong><span>{{ $run->checks->where('status','approved')->count() }} / {{ count($labels) }}</span></div>
<p class="form-hint">The ERP records human assertions and audit events; it cannot independently authenticate reports, provider accounts, warehouse equipment or regulatory approvals from the text submitted here.</p>
</section>
@foreach($run->checks as $check)
@php
$metadata=$labels[$check->check_code]??null;
$secretCategory=($metadata['permission']??null)===null;
$mayRead=!$secretCategory || $reviewer;
$canSubmitCheck=$run->status==='open' && ($canSubmit[$check->check_code]??false)
    && in_array($check->status,['pending','rejected'],true);
$canReviewCheck=$run->status==='open' && $reviewer
    && $check->status==='submitted' && $check->tested_by!==auth()->id();
@endphp
<section class="section-card" style="margin-bottom:14px">
<div class="record"><div><strong>{{ $metadata['label']??$check->check_code }}</strong>
<small>{{ $metadata['guide']??'' }}</small></div><span class="tiny-pill">{{ ucfirst($check->status) }}</span></div>
@if($mayRead && $check->evidence_reference)
<div class="record"><strong>Evidence reference</strong><span>{{ $check->evidence_reference }}</span></div>
<div class="record"><strong>Test findings</strong><span>{{ $check->test_notes }}</span></div>
@if($check->review_notes)<div class="record"><strong>Independent review</strong><span>{{ $check->review_notes }}</span></div>@endif
@elseif($secretCategory && !$reviewer && $check->status!=='pending')
<p class="muted">Restricted security, finance or infrastructure review evidence is visible to authorised management only.</p>
@endif
@if($canSubmitCheck)
<form method="POST" action="{{ route('release.acceptance.submit',['run'=>$run->id,'code'=>$check->check_code]) }}" class="form-stack form-narrow">@csrf
<label class="field"><span>External test record, ticket or verified report reference</span>
<input name="evidence_reference" required minlength="12" maxlength="500" placeholder="QA ticket or independent assessment report ID"></label>
<label class="field"><span>Observed test outcome and outstanding issues</span>
<textarea name="test_notes" required minlength="30" maxlength="3000" rows="3" placeholder="What was actually tested in staging, by whom, and what passed or failed? Never paste secrets."></textarea></label>
<button class="primary-button">Submit for independent review</button></form>
@endif
@if($canReviewCheck)
@foreach(['approve'=>'Accept evidence','reject'=>'Reject evidence'] as $action=>$label)
<form method="POST" action="{{ route('release.acceptance.review',['run'=>$run->id,'code'=>$check->check_code,'action'=>$action]) }}" class="form-stack form-narrow">@csrf
<label class="field"><span>Independent review justification</span><textarea name="review_notes" required minlength="30" maxlength="3000" rows="2" placeholder="Describe report validation, residual risks and any follow-up conditions."></textarea></label>
<button class="secondary-button">{{ $label }}</button></form>
@endforeach
@endif
</section>
@endforeach
@if($run->status==='open' && $owner)
<section class="section-card form-narrow"><h2>Seal acceptance evidence</h2>
@if(!$allApproved)<p class="muted">The campaign cannot be sealed until every item has been independently accepted. Rejected items must be resubmitted with new evidence.</p>@else
<div class="notice"><span>●</span><div><strong>Human sign-off, not production release</strong><p>This preserves the completed staging records. The technical release gate separately checks the production environment, release hash, migrations and database consistency.</p></div></div>@endif
<form method="POST" action="{{ route('release.acceptance.seal',$run->id) }}" class="form-stack">@csrf
<label class="field"><span>Owner's final acceptance statement</span><textarea name="seal_note" required minlength="30" maxlength="3000" rows="3" placeholder="Reference the final accepted scope and explicitly state outstanding constraints."></textarea></label>
<button class="primary-button" @disabled(!$allApproved)>Seal release acceptance record</button></form>
</section>
@endif
<section class="section-card"><h2>Immutable acceptance activity</h2>
@forelse($run->events as $event)
@php
$eventCheck=$event->check_id ? $run->checks->firstWhere('id',$event->check_id) : null;
$restrictedEvent=$eventCheck
    && ($labels[$eventCheck->check_code]['permission']??null)===null
    && !$reviewer;
@endphp
<div class="record"><div><strong>{{ ucwords(str_replace('_',' ',$event->action)) }}</strong>
<small>{{ $restrictedEvent?'Confidential external assessment details are restricted to management.':$event->reason }}</small></div>
<span>{{ $event->created_at->format('d M Y H:i') }}</span></div>
@empty<p class="empty-msg">No acceptance events recorded.</p>@endforelse
</section>
@endsection
