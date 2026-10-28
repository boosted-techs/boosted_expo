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
