@extends('layouts.console')
@section('title','Shifts & Attendance')
@section('content')
<div class="page-intro"><div><span class="eyebrow">STAFF OPERATIONS</span><h1>Shifts & attendance</h1><p>Staff working hours and scheduled duties for {{ $business->name }}. Times use {{ $business->timezone }}.</p></div></div>
<div class="split-panels">
<section class="section-card"><h2>My attendance</h2>
@if($current)
<div class="notice"><span>●</span><div><strong>Clocked in</strong><p>Since {{ $current->clocked_in_at->timezone($business->timezone)->format('d M Y, H:i') }}</p></div></div>
<form action="{{ route('staff.clock-out',$current->id) }}" method="POST" class="form-stack">@csrf
<label class="field"><span>Work note (optional)</span><textarea name="work_note" maxlength="500" rows="2"></textarea></label>
<button class="primary-button">Clock out</button></form>
@else
<p class="muted">Clock in when you begin work. You can select a scheduled shift or record an unscheduled work session.</p>
<form method="POST" action="{{ route('staff.clock-in') }}" class="form-stack">@csrf
<label class="field"><span>Scheduled shift</span><select name="shift_id"><option value="">Unscheduled shift / flexible work</option>
@foreach($myUpcoming as $shift)<option value="{{ $shift->id }}">{{ $shift->starts_at->timezone($business->timezone)->format('d M, H:i') }} – {{ $shift->ends_at->timezone($business->timezone)->format('H:i') }} · {{ $shift->location?:'On duty' }}</option>@endforeach
</select></label>
<button class="primary-button">Clock in</button></form>
@endif
<p class="form-hint">Attendance uses the server clock, not device-adjustable times. Clock-ins and clock-outs keep an audit trail. Hours are not automatic salary, PAYE or NSSF calculations.</p>
</section>
@if($canManage)
<section class="section-card"><h2>Schedule a staff shift</h2>
<form method="POST" action="{{ route('staff.schedule') }}" class="form-stack">@csrf
<label class="field"><span>Employee</span><select name="user_id" required><option value="">Choose active team member</option>
@foreach($staff as $person)<option value="{{ $person->user_id }}" @selected(old('user_id')==$person->user_id)>{{ $person->user->name }} ({{ ucfirst($person->role) }})</option>@endforeach
</select></label>
<div class="form-columns">
<label class="field"><span>Start date & time</span><input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" required></label>
<label class="field"><span>End date & time</span><input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" required></label>
</div>
<label class="field"><span>Work location / station</span><input name="location" maxlength="150" value="{{ old('location') }}" placeholder="e.g. Packing floor / Airport"></label>
<label class="field"><span>Duty notes</span><textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea></label>
<button class="primary-button">Schedule shift</button></form>
</section>
@endif
</div>
<section class="section-card"><h2>{{ $canManage?'Staff shifts':'My shifts' }}</h2>
<div class="table-wrap"><table class="data-table"><thead><tr>
@if($canManage)<th>Employee</th>@endif<th>Starts</th><th>Ends</th><th>Location</th><th>Status</th>@if($canManage)<th>Control</th>@endif
</tr></thead><tbody>
@forelse($shifts as $shift)<tr>
@if($canManage)<td>{{ $shift->user->name }}</td>@endif
<td>{{ $shift->starts_at->timezone($business->timezone)->format('d M Y H:i') }}</td>
<td>{{ $shift->ends_at->timezone($business->timezone)->format('d M Y H:i') }}</td>
<td>{{ $shift->location?:'—' }}</td><td>{{ ucfirst($shift->status) }}</td>
@if($canManage)<td>@if($shift->status==='scheduled' && !$shift->attendances()->exists())
<form method="POST" action="{{ route('staff.cancel',$shift->id) }}" class="form-stack">@csrf
<input name="reason" required maxlength="500" minlength="10" placeholder="Cancellation reason">
<button class="secondary-button">Cancel</button></form>@else—@endif</td>@endif
</tr>@empty<tr><td colspan="{{ $canManage?6:4 }}" class="empty-msg">No shifts recorded.</td></tr>@endforelse
</tbody></table></div>
</section>
<section class="section-card"><h2>{{ $canManage?'Recent attendance':'My attendance history' }}</h2>
<div class="table-wrap"><table class="data-table"><thead><tr>
@if($canManage)<th>Employee</th>@endif<th>Clock-in</th><th>Clock-out</th><th>Shift</th><th>Work notes</th>
</tr></thead><tbody>
@forelse($attendance as $record)<tr>
@if($canManage)<td>{{ $record->user->name }}</td>@endif
<td>{{ $record->clocked_in_at->timezone($business->timezone)->format('d M Y H:i') }}</td>
<td>{{ $record->clocked_out_at?->timezone($business->timezone)->format('d M Y H:i')?:'Currently working' }}</td>
<td>{{ $record->shift?->location?:'Unscheduled' }}</td><td>{{ $record->work_note?:'—' }}</td>
</tr>@empty<tr><td colspan="{{ $canManage?5:4 }}" class="empty-msg">No attendance recorded.</td></tr>@endforelse
</tbody></table></div>
</section>
@endsection
