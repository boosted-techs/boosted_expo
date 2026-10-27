<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceCorrection;
use App\Models\StaffAttendanceEvent;
use App\Models\StaffBreak;
use App\Models\StaffLeave;
use App\Models\StaffPerson;
use App\Models\StaffRota;
use App\Models\StaffShift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PeopleOperations
{
    public function time(string $value): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d\TH:i', $value, 'Africa/Kampala')->utc();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function audit(int $business, int $actor, string $action, string $reason, array $details): void
    {
        app(PlatformAccess::class)->audit($business, $actor, 'people.'.$action, $reason, [], $details);
    }

    public function syncPerson(Business $business, int $user): StaffPerson
    {
        $member = BusinessMembership::query()->where('business_id', $business->id)->where('user_id', $user)->where('active', true)->with('user')->firstOrFail();

        return StaffPerson::query()->firstOrCreate(['business_id' => $business->id, 'user_id' => $user], ['name' => $member->user->name, 'staff_code' => 'MEM-'.$member->id, 'kind' => 'employee']);
    }

    public function assertAvailable(StaffPerson $person, Carbon $start, Carbon $end, ?int $exceptShift = null): void
    {
        if (! $end->gt($start) || $start->diffInHours($end) > 18) {
            $this->fail('ends_at', 'A shift must end after it starts and last at most 18 hours.');
        }
        $shifts = StaffShift::query()->where('business_id', $person->business_id)->where('status', '!=', 'cancelled')->where(fn ($q) => $q->where('staff_person_id', $person->id)->when($person->user_id, fn ($q) => $q->orWhere('user_id', $person->user_id)))->when($exceptShift, fn ($q) => $q->where('id', '!=', $exceptShift))->where('starts_at', '<', $end)->where('ends_at', '>', $start);
        if ($shifts->exists()) {
            $this->fail('starts_at', 'This person already has an overlapping shift, including draft rota work.');
        }
        if (StaffLeave::query()->where('business_id', $person->business_id)->where('staff_person_id', $person->id)->where('status', 'approved')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
            $this->fail('starts_at', 'This person is unavailable or on approved leave.');
        }
    }

    public function shift(Business $business, StaffRota $rota, array $data, int $actor): StaffShift
    {
        return DB::transaction(function () use ($business, $rota, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'people');
            $rota = StaffRota::query()->where('business_id', $business->id)->where('status', 'draft')->findOrFail($rota->id);
            $person = app(OrderOperations::class)->person($business->id, (int) $data['staff_person_id']);
            $start = $this->time($data['starts_at']);
            $end = $this->time($data['ends_at']);
            $date = $start->copy()->timezone('Africa/Kampala')->toDateString();
            $week = $rota->week_start->toDateString();
            if ($date < $week || $date > $rota->week_start->copy()->addDays(6)->toDateString()) {
                $this->fail('starts_at', 'Start the shift within the selected rota week. Overnight endings are allowed.');
            }
            $this->assertAvailable($person, $start, $end);
            if (! empty($data['required_skill']) && ! in_array($data['required_skill'], $person->skills ?? [], true)) {
                $this->fail('required_skill', 'The person does not have the required recorded skill.');
            }
            $shift = StaffShift::create([...$data, 'business_id' => $business->id, 'staff_rota_id' => $rota->id, 'user_id' => $person->user_id, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'scheduled', 'created_by' => $actor]);
            $this->audit($business->id, $actor, 'shift_added', 'Draft rota shift assigned.', ['shift_id' => $shift->id, 'person_id' => $person->id]);

            return $shift;
        }, 3);
    }

    public function publish(Business $business, StaffRota $rota, int $actor): void
    {
        DB::transaction(function () use ($business, $rota, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'people');
            $rota->refresh();
            abort_unless($rota->business_id === $business->id, 404);
            if ($rota->status === 'published') {
                return;
            }
            $shifts = StaffShift::query()->where('business_id', $business->id)->where('staff_rota_id', $rota->id)->where('status', 'scheduled')->with('person')->get();
            abort_unless($shifts->isNotEmpty(), 422, 'Add at least one shift before publishing.');
            foreach ($shifts as $s) {
                $p = app(OrderOperations::class)->person($business->id, $s->staff_person_id);
                $this->assertAvailable($p, $s->starts_at, $s->ends_at, $s->id);
            }
            foreach (DB::table('staff_rota_requirements')->where('business_id', $business->id)->where('staff_rota_id', $rota->id)->get() as $requirement) {
                $covered = $shifts->filter(fn ($s) => $s->location === $requirement->location && $s->starts_at->lte(Carbon::parse($requirement->starts_at)) && $s->ends_at->gte(Carbon::parse($requirement->ends_at)) && (! $requirement->required_skill || in_array($requirement->required_skill, $s->person?->skills ?? [], true)))->unique('staff_person_id')->count();
                if ($covered < $requirement->required_people) {
                    $this->fail('coverage', 'Staffing / skill coverage is short. Add or adjust shifts before publishing.');
                }
            }
            $shifts->each(fn ($s) => $s->update(['published_at' => now()]));
            $rota->update(['status' => 'published', 'published_at' => now(), 'published_by' => $actor]);
            $this->audit($business->id, $actor, 'rota_published', 'Weekly rota published.', ['rota_id' => $rota->id, 'shift_count' => $shifts->count()]);
        }, 3);
    }

    public function replace(Business $business, StaffShift $shift, array $data, int $actor): void
    {
        DB::transaction(function () use ($business, $shift, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'people');
            $shift->refresh();
            abort_unless($shift->business_id === $business->id && $shift->status === 'scheduled' && ! $shift->attendances()->exists(), 422, 'Only an unused scheduled shift can be replaced.');
            $person = app(OrderOperations::class)->person($business->id, (int) $data['staff_person_id']);
            $this->assertAvailable($person, $shift->starts_at, $shift->ends_at, $shift->id);
            if ($shift->required_skill && ! in_array($shift->required_skill, $person->skills ?? [], true)) {
                $this->fail('staff_person_id', 'The replacement needs the recorded shift skill.');
            }
            $before = $shift->staff_person_id;
            $shift->update(['status' => 'cancelled', 'cancelled_by' => $actor, 'cancel_reason' => $data['reason']]);
            $new = StaffShift::create(['business_id' => $business->id, 'staff_rota_id' => $shift->staff_rota_id, 'staff_person_id' => $person->id, 'user_id' => $person->user_id, 'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at, 'location' => $shift->location, 'notes' => 'Replacement for shift #'.$shift->id.': '.$data['reason'], 'required_skill' => $shift->required_skill, 'status' => 'scheduled', 'created_by' => $actor, 'published_at' => $shift->published_at]);
            $this->audit($business->id, $actor, 'shift_replaced', $data['reason'], ['old_shift_id' => $shift->id, 'new_shift_id' => $new->id, 'previous_person' => $before, 'person_id' => $person->id]);
        }, 3);
    }

    public function leave(Business $business, array $data, int $actor): void
    {
        DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'people');
            $person = app(OrderOperations::class)->person($business->id, (int) $data['staff_person_id']);
            $start = $this->time($data['starts_at']);
            $end = $this->time($data['ends_at']);
            if (! $end->gt($start)) {
                $this->fail('ends_at', 'Availability end must follow its start.');
            }
            if (StaffShift::query()->where('business_id', $business->id)->where(fn ($q) => $q->where('staff_person_id', $person->id)->when($person->user_id, fn ($q) => $q->orWhere('user_id', $person->user_id)))->where('status', 'scheduled')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                $this->fail('starts_at', 'Replace or cancel overlapping shifts before approving leave.');
            }
            $leave = StaffLeave::create([...$data, 'business_id' => $business->id, 'starts_at' => $start, 'ends_at' => $end, 'recorded_by' => $actor]);
            $this->audit($business->id, $actor, 'leave_recorded', $data['reason'], ['leave_id' => $leave->id]);
        }, 3);
    }

    public function attendance(Business $business, array $data, int $actor): StaffAttendance
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'people');
            $person = app(OrderOperations::class)->person($business->id, (int) $data['staff_person_id']);
            $start = $this->time($data['clocked_in_at']);
            $end = $this->time($data['clocked_out_at']);
            if (! $end->gt($start) || $end->isFuture() || $start->diffInHours($end) > 24) {
                $this->fail('clocked_out_at', 'Record a completed observation of at most 24 hours, ending no later than now.');
            }
            if (StaffAttendance::query()->where('business_id', $business->id)->where(fn ($q) => $q->where('staff_person_id', $person->id)->when($person->user_id, fn ($q) => $q->orWhere('user_id', $person->user_id)))->where('clocked_in_at', '<', $end)->where(fn ($q) => $q->whereNull('clocked_out_at')->orWhere('clocked_out_at', '>', $start))->exists()) {
                $this->fail('clocked_in_at', 'Attendance overlaps an existing observation.');
            }
            $shift = null;
            if (! empty($data['staff_shift_id'])) {
                $shift = StaffShift::query()->where('business_id', $business->id)->where('staff_person_id', $person->id)->whereNotNull('published_at')->where('status', 'scheduled')->findOrFail($data['staff_shift_id']);
                abort_if($shift->attendances()->exists(), 422, 'Shift already has attendance.');
            }
            $entry = StaffAttendance::create(['business_id' => $business->id, 'staff_person_id' => $person->id, 'user_id' => $person->user_id, 'staff_shift_id' => $shift?->id, 'clocked_in_at' => $start, 'clocked_out_at' => $end, 'recorded_by' => $actor, 'work_note' => $data['reason']]);
            $shift?->update(['status' => 'completed']);
            $this->attendanceEvent($entry, $actor, 'supervisor_recorded', $data['reason']);

            return $entry;
        }, 3);
    }

    private function attendanceEvent(StaffAttendance $entry, int $actor, string $action, string $note): void
    {
        StaffAttendanceEvent::create(['business_id' => $entry->business_id, 'attendance_id' => $entry->id, 'actor_id' => $actor, 'action' => $action, 'detail' => mb_substr($note, 0, 500), 'created_at' => now()]);
    }

    public function breakAction(Business $business, StaffAttendance $entry, string $action, int $actor): void
    {
        DB::transaction(function () use ($business, $entry, $action, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'people');
            $entry->refresh();
            abort_unless($entry->business_id === $business->id && $entry->user_id === $actor, 404);
            abort_if($entry->clocked_out_at || $entry->effective_out_at || $entry->approved_at, 422, 'Breaks require an open attendance record.');
            $open = StaffBreak::query()->where('business_id', $business->id)->where('staff_attendance_id', $entry->id)->whereNull('ends_at')->first();
            if ($action === 'start') {
                abort_if($open, 422, 'A break is already open.');
                StaffBreak::create(['business_id' => $business->id, 'staff_attendance_id' => $entry->id, 'starts_at' => now(), 'recorded_by' => $actor]);
            } else {
                abort_unless($open, 422, 'No open break to end.');
                $open->update(['ends_at' => now()]);
            }
            $this->attendanceEvent($entry, $actor, 'break_'.$action, 'Server-timed break '.$action.'.');
        }, 3);
    }

    public function correction(Business $business, StaffAttendance $entry, array $data, int $actor): void
    {
        DB::transaction(function () use ($business, $entry, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'people');
            $entry->refresh();
            abort_unless($entry->business_id === $business->id, 404);
            abort_if($entry->approved_at, 422, 'Approved time is locked; resolve payroll/review impact before a further correction.');
            $start = $this->time($data['proposed_in_at']);
            $end = $this->time($data['proposed_out_at']);
            if (! $end->gt($start) || $end->isFuture() || $start->diffInHours($end) > 24) {
                $this->fail('proposed_out_at', 'Proposed time must be a completed interval of at most 24 hours.');
            }
            abort_if(StaffAttendanceCorrection::query()->where('staff_attendance_id', $entry->id)->where('status', 'pending')->exists(), 422, 'Review the pending correction first.');
            StaffAttendanceCorrection::create([...$data, 'business_id' => $business->id, 'staff_attendance_id' => $entry->id, 'proposed_in_at' => $start, 'proposed_out_at' => $end, 'requested_by' => $actor]);
            $this->attendanceEvent($entry, $actor, 'correction_requested', $data['reason']);
        }, 3);
    }

    public function reviewCorrection(Business $business, StaffAttendanceCorrection $correction, string $decision, string $note, int $actor): void
    {
        DB::transaction(function () use ($business, $correction, $decision, $note, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'people');
            $correction->refresh();
            abort_unless($correction->business_id === $business->id, 404);
            abort_unless($correction->status === 'pending', 422, 'Correction already reviewed.');
            abort_if($correction->requested_by === $actor, 403, 'Another authorized reviewer must review the correction.');
            $entry = StaffAttendance::query()->where('business_id', $business->id)->findOrFail($correction->staff_attendance_id);
            abort_if($entry->user_id === $actor || $entry->approved_at, 403, 'You cannot approve your own attendance or alter approved time.');
            if ($decision === 'approve') {
                $start = $correction->proposed_in_at;
                $end = $correction->proposed_out_at;
                $overlap = StaffAttendance::query()->where('business_id', $business->id)->where('id', '!=', $entry->id)->where(fn ($q) => $q->when($entry->staff_person_id, fn ($q) => $q->where('staff_person_id', $entry->staff_person_id))->when($entry->user_id, fn ($q) => $q->orWhere('user_id', $entry->user_id)))->whereRaw('COALESCE(effective_in_at,clocked_in_at) < ?', [$end])->where(fn ($q) => $q->whereRaw('COALESCE(effective_out_at,clocked_out_at) > ?', [$start])->orWhere(fn ($q) => $q->whereNull('effective_out_at')->whereNull('clocked_out_at')))->exists();
                if ($overlap) {
                    $this->fail('proposed_in_at', 'Corrected attendance overlaps another observation.');
                }
                $breaks = StaffBreak::query()->where('business_id', $business->id)->where('staff_attendance_id', $entry->id)->get();
                foreach ($breaks as $break) {
                    abort_if(! $break->ends_at || $break->starts_at->lt($start) || $break->ends_at->gt($end), 422, 'Close all breaks and keep them inside the corrected interval.');
                }
                $entry->update(['effective_in_at' => $start, 'effective_out_at' => $end]);
                if ($entry->staff_shift_id) {
                    StaffShift::query()->where('business_id', $business->id)->whereKey($entry->staff_shift_id)->update(['status' => 'completed']);
                }
            }
            $correction->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->attendanceEvent($entry, $actor, 'correction_'.$decision, $note);
        }, 3);
    }

    public function approveTime(Business $business, StaffAttendance $entry, string $note, int $actor): void
    {
        DB::transaction(function () use ($business, $entry, $note, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'people');
            $entry->refresh();
            abort_unless($entry->business_id === $business->id, 404);
            abort_if($entry->recorded_by === $actor || $entry->user_id === $actor, 403, 'An independent reviewer must approve recorded time.');
            abort_if($entry->approved_at, 422, 'Time already approved.');
            $start = $entry->effective_in_at ?? $entry->clocked_in_at;
            $end = $entry->effective_out_at ?? $entry->clocked_out_at;
            abort_unless($end && $end->gt($start), 422, 'Resolve the missing clock-out first.');
            abort_if(StaffBreak::query()->where('staff_attendance_id', $entry->id)->whereNull('ends_at')->exists() || StaffAttendanceCorrection::query()->where('staff_attendance_id', $entry->id)->where('status', 'pending')->exists(), 422, 'Resolve open breaks and pending corrections before approval.');
            $entry->update(['approved_at' => now(), 'approved_by' => $actor, 'review_note' => $note]);
            $this->attendanceEvent($entry, $actor, 'time_approved', $note);
        }, 3);
    }
}
