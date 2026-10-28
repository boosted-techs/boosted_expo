<section class="section-card"><h2>{{ $canManage?'Staff shifts':'My shifts' }}</h2>
<div class="table-wrap"><table class="data-table"><thead><tr>
@if($canManage)<th>Employee</th>@endif<th>Starts</th><th>Ends</th><th>Location</th><th>Status</th>@if($canManage)<th>Control</th>@endif
</tr></thead><tbody>
@forelse($shifts as $shift)<tr>
@if($canManage)<td>{{ $shift->user->name }}</td>@endif
<td>{{ $shift->starts_at->timezone($business->timezone)->format('d M Y H:i') }}</td>
<td>{{ $shift->ends_at->timezone($business->timezone)->format('d M Y H:i') }}</td>
<td>{{ $shift->location?:'—' }}</td><td>{{ ucfirst($shift->status) }}</td>
@if($canManage)<td>@if($shift->status==='scheduled' && $shift->attendances_count===0)
<form method="POST" action="{{ route('staff.cancel',$shift->id) }}" class="form-stack">@csrf
<input name="reason" required maxlength="500" minlength="10" placeholder="Cancellation reason">
<button class="secondary-button">Cancel</button></form>@else—@endif</td>@endif
</tr>@empty<tr><td colspan="{{ $canManage?6:4 }}" class="empty-msg">No shifts recorded.</td></tr>@endforelse
</tbody></table></div>{{ $shifts->links() }}
</section>
