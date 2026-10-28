@extends('layouts.console')
@section('title', 'Team & Access')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ACCESS MANAGEMENT</span><h1>{{ ['members'=>'Team & Access','invitations'=>'Pending invitations','history'=>'Access history'][$activity] }}</h1><p>Manage staff logins and permissions.</p></div></div>
@if(count($roles))<details data-modal class="section-card"><summary>Invite team member</summary><p>Invitations expire after three days.</p><form method="POST" action="{{ route('team.invite') }}" class="form-stack">@csrf
<label class="field"><span>Email address</span><input name="email" type="email" value="{{ old('email') }}" required></label>
<label class="field"><span>Role</span><select name="role" required>
@foreach($roles as $role)
<option value="{{ $role }}" @selected(old('role')===$role)>{{ ucfirst($role) }}</option>
@endforeach
</select></label>
<button class="primary-button" type="submit">Send invitation</button>
</form></details>@endif
@if($activity==='members')
<section class="section-card"><h2>Current team</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody>@forelse($members as $member)<tr><td><strong>{{ $member->user->name }}</strong></td><td>{{ $member->user->email }}</td><td>{{ ucfirst($member->role) }}</td><td><span class="status-badge {{ $member->active?'':'hold' }}">{{ $member->active?'Active':'Suspended' }}</span></td><td>@if($canManage && $member->user_id!==auth()->id() && $member->role!=='owner' && ($actorRole==='owner' || $member->role!=='admin'))<details data-modal><summary>Update access</summary><form method="POST" action="{{ route('team.members.update',$member->id) }}" class="form-stack">@csrf @method('PUT')
<label class="field"><span>Access role</span><select name="role">
@foreach(\App\Support\BusinessPermissions::INVITABLE_ROLES as $role)
@if($actorRole==='owner' || $role!=='admin')
<option value="{{ $role }}" @selected($member->role===$role)>{{ ucfirst($role) }}</option>
@endif
@endforeach</select></label>
<label class="field"><span>Status</span><select name="active">
<option value="1" @selected($member->active)>Active</option>
<option value="0" @selected(!$member->active)>Suspended</option></select></label>
<label class="field"><span>Reason for access change</span><input name="reason" required maxlength="500" minlength="10" placeholder="Role change or suspension reason"></label>
<button class="secondary-button">Update staff access</button>
</form></details>@else—@endif</td></tr>@empty<tr><td colspan="5">No team members yet.</td></tr>@endforelse</tbody></table></div></section>
@elseif($activity==='invitations')
<section class="section-card"><h2>Pending invitations</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Email</th><th>Role</th><th>Expires</th><th>Status</th></tr></thead><tbody>@forelse($pending as $invite)<tr><td>{{ $invite->email }}</td><td>{{ ucfirst($invite->role) }}</td><td>{{ $invite->expires_at->format('d M Y, H:i') }}</td><td><span class="status-badge info">Pending</span></td></tr>@empty<tr><td colspan="4">No outstanding invitations.</td></tr>@endforelse</tbody></table></div></section>
@else
<section class="section-card"><h2>Recent access changes</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Action</th><th>Previous role</th><th>New role</th><th>Reason</th><th>Date</th></tr></thead><tbody>@forelse($audit as $event)<tr><td>{{ ucfirst(str_replace('_',' ',$event->action)) }}</td><td>{{ ucfirst($event->previous_role??'—') }}</td><td>{{ ucfirst($event->new_role??'—') }}</td><td>{{ $event->reason }}</td><td>{{ $event->created_at->format('d M Y H:i') }}</td></tr>@empty<tr><td colspan="5">No access changes recorded.</td></tr>@endforelse</tbody></table></div>{{ $audit->links() }}</section>
@endif
@endsection
