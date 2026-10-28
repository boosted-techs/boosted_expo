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
</tbody></table></div>{{ $attendance->links() }}
</section>
