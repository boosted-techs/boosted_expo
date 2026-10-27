@extends('layouts.console')
@section('title','Release Acceptance')
@section('content')
<div class="page-intro"><div><span class="eyebrow">STAGING ACCEPTANCE</span>
<h1>Release acceptance</h1><p>Document the exact tested build, operational scenarios and independent management reviews. A sealed campaign alone never authorises deployment.</p></div></div>
<div class="notice"><span>●</span><div><strong>Use an actual release artifact fingerprint</strong>
<p>Use the SHA-256 of the approved release archive prepared by operations, not a test placeholder or the name of a Git branch. Tests must be conducted in isolated staging using approved sample data. Do not enter passwords, customer identity documents or private keys in the evidence forms.</p></div></div>
@if($isOwner)
<section class="section-card form-narrow"><h2>Open a release acceptance campaign</h2>
<form method="POST" action="{{ route('release.acceptance.begin') }}" class="form-stack">@csrf
<label class="field"><span>Release label</span><input name="release_label" required minlength="4" maxlength="100" value="{{ old('release_label') }}" placeholder="Export ERP candidate 2026.10"></label>
<label class="field"><span>Exact artifact SHA-256 (64 hex characters)</span>
<input name="artifact_sha256" required minlength="64" maxlength="64" value="{{ old('artifact_sha256') }}" placeholder="Copied from the reviewed build manifest"></label>
<label class="field"><span>Compiled frontend build SHA-256 (64 hex characters)</span>
<input name="assets_sha256" required minlength="64" maxlength="64" value="{{ old('assets_sha256') }}" placeholder="Obtained from expo:build-fingerprint after npm ci and build"></label>
<label class="field"><span>Staging environment, scope and excluded connections</span>
<textarea name="scope_note" required minlength="30" maxlength="3000" rows="4" placeholder="Describe which staging site, seeded test records, modules and external services were used. Explain any disabled provider or printer.">{{ old('scope_note') }}</textarea></label>
<label class="check-row"><input type="checkbox" name="confirm_staging" value="1" required> I confirm this is a staging acceptance exercise, not live payment or customs testing.</label>
<button class="primary-button">Start acceptance campaign</button>
</form></section>
@endif
<section class="section-card"><h2>Acceptance campaigns</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Release</th><th>Fingerprint</th><th>Checks</th><th>Status</th><th>Details</th></tr></thead><tbody>
@forelse($runs as $run)
<tr><td><strong>{{ $run->release_label }}</strong><small>Opened {{ $run->created_at->format('d M Y H:i') }}</small></td>
<td><code>{{ substr($run->artifact_sha256,0,14) }}…{{ substr($run->artifact_sha256,-8) }}</code></td>
<td>{{ $run->accepted_checks_count }} / {{ count(\App\Services\ReleaseAcceptanceManager::CHECKS) }}</td>
<td>{{ $run->status==='sealed'?'Evidence sealed':'In progress' }}</td>
<td><a href="{{ route('release.acceptance.show',$run->id) }}" class="action-link">Review</a></td></tr>
@empty<tr><td colspan="5" class="empty-msg">No release acceptance campaign recorded for this business.</td></tr>@endforelse
</tbody></table></div>{{ $runs->links() }}
</section>
@endsection
