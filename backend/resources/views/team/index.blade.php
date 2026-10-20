@extends('layouts.console')
@section('title', 'Team & Access')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ACCESS MANAGEMENT</span><h1>Team & Access</h1><p>Invite staff and view access within {{ $business->name }}.</p></div></div>
<div class="split-panels">
<section class="section-card">
<h2>Invite team member</h2><p class="muted">An invitation is sent by email and expires after three days.</p>
<form method="POST" action="{{ route('team.invite') }}" class="form-stack">@csrf
<label class="field"><span>Email address</span><input name="email" type="email" value="{{ old('email') }}" required></label>
<label class="field"><span>Role</span><select name="role" required>
@foreach($roles as $role)
<option value="{{ $role }}" @selected(old('role')===$role)>{{ ucfirst($role) }}</option>
@endforeach
</select></label>
<button class="primary-button" type="submit">Send invitation</button>
</form>
</section>
<section class="section-card"><h2>Current team</h2>
@forelse($members as $member)
<div class="record"><div><strong>{{ $member->user->name }}</strong><small>{{ $member->user->email }} · {{ ucfirst($member->role) }}</small></div><span class="tiny-pill">{{ $member->active ? 'Active' : 'Inactive' }}</span></div>
@empty<p class="empty-msg">No team members yet.</p>@endforelse
</section>
</div>
<section class="section-card"><h2>Pending invitations</h2>
@forelse($pending as $invite)
<div class="record"><div><strong>{{ $invite->email }}</strong><small>{{ ucfirst($invite->role) }} · expires {{ $invite->expires_at->format('d M Y, H:i') }}</small></div><span class="tiny-pill">Pending</span></div>
@empty<p class="empty-msg">No outstanding invitations.</p>@endforelse
</section>
@endsection