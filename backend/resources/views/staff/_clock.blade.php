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
<p class="form-hint">Clock in and out to record your attendance. Payroll is prepared separately.</p>
</section>
