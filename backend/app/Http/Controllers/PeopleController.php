<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\OrderTask;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceCorrection;
use App\Models\StaffBreak;
use App\Models\StaffLeave;
use App\Models\StaffPerson;
use App\Models\StaffRota;
use App\Models\StaffShift;
use App\Services\OrderOperations;
use App\Services\PeopleOperations;
use App\Services\PlatformAccess;
use App\Support\BusinessPermissions;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PeopleController extends Controller
{
    private function manage(Request $request): Business
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'staff.manage'), 403);

        return $request->attributes->get('activeBusiness');
    }

    public function index(Request $request): View
    {
        $b = $request->attributes->get('activeBusiness');
        $manager = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'staff.manage');
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'staff.clock'), 403);
        $activities = [
            'weekly' => ['Weekly schedule', 'Review assignments and shifts for the week.'],
            'directory' => ['Staff directory', 'Find employees and casual workers.'],
            'register' => ['Register a worker', 'Add a staff record and skills.'],
            'rotas' => ['Weekly rotas', 'Manage draft rotas and publish assignments.'],
            'rota-create' => ['Create a rota', 'Start a weekly rota or reuse a previous one.'],
            'coverage' => ['Staffing coverage', 'Check the people and skills needed at each location.'],
            'availability' => ['Availability & leave', 'Record approved leave and availability.'],
            'attendance' => ['Team attendance', 'Review observed work and approve resolved time.'],
            'attendance-record' => ['Record attendance', 'Record a completed supervisor observation.'],
            'corrections' => ['Time corrections', 'Review proposed attendance corrections.'],
            'tasks' => ['Assigned work', 'Review tasks and update your work.'],
        ];
        $activity = $request->route('activity', 'overview');
        abort_unless($activity === 'overview' || isset($activities[$activity]), 404);
        abort_if(! $manager && ! in_array($activity, ['overview', 'weekly', 'attendance', 'tasks'], true), 403);
        [$activityTitle,$activityDescription] = $activities[$activity] ?? ['People & work', 'Choose the activity you want to work on.'];
        $data = $request->validate(['week' => ['nullable', 'date_format:Y-m-d']]);
        $week = Carbon::parse($data['week'] ?? today('Africa/Kampala')->toDateString(), 'Africa/Kampala')->startOfWeek();
        $end = $week->copy()->addWeek();
        $people = StaffPerson::query()->where('business_id', $b->id)->when(! $manager, fn ($q) => $q->where('user_id', $request->user()->id))->orderBy('name')->get();
        $shifts = StaffShift::query()->where('business_id', $b->id)->where('starts_at', '>=', $week->copy()->utc())->where('starts_at', '<', $end->copy()->utc())->when(! $manager, fn ($q) => $q->where('user_id', $request->user()->id)->whereNotNull('published_at'))->with('person', 'user', 'attendances')->orderBy('starts_at')->get();
        $attendance = StaffAttendance::query()->where('business_id', $b->id)->when(! $manager, fn ($q) => $q->where('user_id', $request->user()->id))->with('person', 'user', 'shift')->orderByDesc('clocked_in_at')->workspacePaginate(25);
        $corrections = StaffAttendanceCorrection::query()->where('business_id', $b->id)->where('status', 'pending')->when(! $manager, fn ($q) => $q->where('requested_by', $request->user()->id))->latest('id')->workspacePaginate(25, ['*'], 'corrections_page');
        $rotas = $manager ? StaffRota::query()->where('business_id', $b->id)->whereDate('week_start', $week->toDateString())->latest('id')->get() : collect();
        $members = $manager ? BusinessMembership::query()->where('business_id', $b->id)->where('active', true)->with('user')->get() : collect();
        $leaves = $manager ? StaffLeave::query()->where('business_id', $b->id)->where('ends_at', '>=', $week->copy()->utc())->where('starts_at', '<', $end->copy()->utc())->with('person')->get() : collect();
        $tasks = OrderTask::query()->where('business_id', $b->id)->whereIn('staff_person_id', $people->modelKeys())->whereNotIn('status', ['completed', 'cancelled'])->with('person', 'order')->orderBy('due_at')->workspacePaginate(25, ['*'], 'tasks_page');
        $breaks = StaffBreak::query()->where('business_id', $b->id)->whereIn('staff_attendance_id', $attendance->modelKeys())->get()->groupBy('staff_attendance_id');
        $coverage = [];
        if ($manager) {
            foreach (DB::table('staff_rota_requirements')->where('business_id', $b->id)->whereIn('staff_rota_id', $rotas->modelKeys())->get() as $requirement) {
                $covered = $shifts->filter(fn ($s) => $s->staff_rota_id === $requirement->staff_rota_id && $s->status !== 'cancelled' && $s->location === $requirement->location && $s->starts_at->lte(Carbon::parse($requirement->starts_at)) && $s->ends_at->gte(Carbon::parse($requirement->ends_at)) && (! $requirement->required_skill || in_array($requirement->required_skill, $s->person?->skills ?? [], true)))->unique('staff_person_id')->count();
                $coverage[] = ['requirement' => $requirement, 'covered' => $covered];
            }
        }
        $templateRotas = $manager ? StaffRota::query()->where('business_id', $b->id)->latest('week_start')->get() : collect();

        return view('people.index', compact('activity', 'activities', 'activityTitle', 'activityDescription', 'coverage', 'templateRotas', 'b', 'manager', 'week', 'end', 'people', 'shifts', 'attendance', 'corrections', 'rotas', 'members', 'leaves', 'tasks', 'breaks'));
    }

    public function person(Request $request): RedirectResponse
    {
        $b = $this->manage($request);
        $data = $request->validate(['staff_code' => ['required', 'alpha_dash', 'max:50', Rule::unique('staff_people')->where('business_id', $b->id)], 'name' => ['required', 'string', 'max:150'], 'kind' => ['required', Rule::in(['employee', 'casual'])], 'user_id' => ['nullable', 'integer'], 'phone' => ['nullable', 'string', 'max:50'], 'skills_text' => ['nullable', 'string', 'max:500']]);
        DB::transaction(function () use ($b, $data, $request) {
            DB::table('businesses')->where('id', $b->id)->lockForUpdate()->first();
            if (! empty($data['user_id'])) {
                BusinessMembership::query()->where('business_id', $b->id)->where('user_id', $data['user_id'])->where('active', true)->firstOrFail();
                abort_if(StaffPerson::query()->where('business_id', $b->id)->where('user_id', $data['user_id'])->exists(), 422, 'This login already has a staff record.');
            }
            $person = StaffPerson::create([...collect($data)->except('skills_text')->all(), 'business_id' => $b->id, 'skills' => array_values(array_unique(array_filter(array_map('trim', explode(',', $data['skills_text'] ?? '')))))]);
            app(PlatformAccess::class)->audit($b->id, $request->user()->id, 'people.registered', 'Staff record created; no login or money was created.', [], ['staff_person_id' => $person->id]);
        });

        return back()->with('status', 'Staff record saved. A staff record grants no system login or payroll payment.');
    }

    public function rota(Request $request, PeopleOperations $people): RedirectResponse
    {
        $b = $this->manage($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'week_start' => ['required', 'date_format:Y-m-d'], 'request_key' => ['required', 'uuid'], 'copy_rota_id' => ['nullable', 'integer']]);
        DB::transaction(function () use ($b, $data, $request, $people) {
            DB::table('businesses')->where('id', $b->id)->lockForUpdate()->first();
            $week = Carbon::parse($data['week_start'], 'Africa/Kampala');
            abort_unless($week->isMonday(), 422, 'Choose Monday as the rota week start.');
            $old = StaffRota::query()->where('business_id', $b->id)->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_unless($old->week_start->toDateString() === $data['week_start'], 409);

                return;
            }
            $source = empty($data['copy_rota_id']) ? null : StaffRota::query()->where('business_id', $b->id)->findOrFail($data['copy_rota_id']);
            $rota = StaffRota::create([...collect($data)->except('copy_rota_id')->all(), 'business_id' => $b->id, 'created_by' => $request->user()->id]);
            if ($source) {
                $days = (int) Carbon::parse($source->week_start->toDateString(), 'Africa/Kampala')->diffInDays($week, false);
                foreach (StaffShift::query()->where('business_id', $b->id)->where('staff_rota_id', $source->id)->where('status', '!=', 'cancelled')->get() as $shift) {
                    $people->shift($b, $rota, ['staff_person_id' => $shift->staff_person_id, 'starts_at' => $shift->starts_at->copy()->timezone('Africa/Kampala')->addDays($days)->format('Y-m-d\TH:i'), 'ends_at' => $shift->ends_at->copy()->timezone('Africa/Kampala')->addDays($days)->format('Y-m-d\TH:i'), 'location' => $shift->location, 'required_skill' => $shift->required_skill, 'notes' => 'Copied from rota #'.$source->id], $request->user()->id);
                }
                foreach (DB::table('staff_rota_requirements')->where('business_id', $b->id)->where('staff_rota_id', $source->id)->get() as $requirement) {
                    DB::table('staff_rota_requirements')->insert(['business_id' => $b->id, 'staff_rota_id' => $rota->id, 'starts_at' => Carbon::parse($requirement->starts_at)->addDays($days), 'ends_at' => Carbon::parse($requirement->ends_at)->addDays($days), 'location' => $requirement->location, 'required_skill' => $requirement->required_skill, 'required_people' => $requirement->required_people, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }, 3);

        return back()->with('status', 'Draft weekly rota saved; publish after reviewing coverage and availability.');
    }

    public function shift(Request $request, int $rota, PeopleOperations $people): RedirectResponse
    {
        $b = $this->manage($request);
        $data = $request->validate(['staff_person_id' => ['required', 'integer'], 'starts_at' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_at' => ['required', 'date_format:Y-m-d\TH:i'], 'location' => ['nullable', 'string', 'max:150'], 'required_skill' => ['nullable', 'string', 'max:50'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $people->shift($b, StaffRota::query()->where('business_id', $b->id)->findOrFail($rota), $data, $request->user()->id);

        return back()->with('status', 'Shift added to the draft rota.');
    }

    public function coverage(Request $request, int $rota, PeopleOperations $people): RedirectResponse
    {
        $b = $this->manage($request);
        $data = $request->validate(['starts_at' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_at' => ['required', 'date_format:Y-m-d\TH:i'], 'location' => ['required', 'string', 'max:150'], 'required_skill' => ['nullable', 'string', 'max:50'], 'required_people' => ['required', 'integer', 'between:1,10000']]);
        DB::transaction(function () use ($b, $rota, $data, $people) {
            DB::table('businesses')->where('id', $b->id)->lockForUpdate()->first();
            $r = StaffRota::query()->where('business_id', $b->id)->where('status', 'draft')->findOrFail($rota);
            $start = $people->time($data['starts_at']);
            $end = $people->time($data['ends_at']);
            abort_unless($end->gt($start) && $start->copy()->timezone('Africa/Kampala')->toDateString() >= $r->week_start->toDateString() && $start->copy()->timezone('Africa/Kampala')->toDateString() <= $r->week_start->copy()->addDays(6)->toDateString(), 422, 'Coverage must start within this week and end after it starts.');
            DB::table('staff_rota_requirements')->insert([...$data, 'business_id' => $b->id, 'staff_rota_id' => $r->id, 'starts_at' => $start, 'ends_at' => $end, 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('status', 'Coverage requirement saved for the rota review.');
    }

    public function publish(Request $request, int $rota, PeopleOperations $people): RedirectResponse
    {
        $b = $this->manage($request);
        $people->publish($b, StaffRota::query()->where('business_id', $b->id)->findOrFail($rota), $request->user()->id);

        return back()->with('status', 'Weekly rota published.');
    }

    public function absence(Request $request, int $shift): RedirectResponse
    {
        $b = $this->manage($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        DB::transaction(function () use ($b, $shift, $data, $request) {
            app(OrderOperations::class)->lockForWork($b->id, 'people');
            $record = StaffShift::query()->where('business_id', $b->id)->findOrFail($shift);
            abort_unless($record->status === 'scheduled' && $record->ends_at->isPast() && ! $record->attendances()->exists(), 422, 'Only an elapsed unused shift can be marked absent.');
            $record->update(['status' => 'absent', 'cancelled_by' => $request->user()->id, 'cancel_reason' => $data['reason']]);
            app(PlatformAccess::class)->audit($b->id, $request->user()->id, 'people.absence', $data['reason'], [], ['shift_id' => $record->id]);
        });

        return back()->with('status', 'Absence recorded. No zero-hour attendance or pay deduction was invented.');
    }

    public function replace(Request $request, int $shift, PeopleOperations $people): RedirectResponse
    {
        $b = $this->manage($request);
        $data = $request->validate(['staff_person_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $people->replace($b, StaffShift::query()->where('business_id', $b->id)->findOrFail($shift), $data, $request->user()->id);

        return back()->with('status', 'Replacement recorded; previous shift retained.');
    }

    public function leave(Request $request, PeopleOperations $people): RedirectResponse
    {
        $b = $this->manage($request);
        $data = $request->validate(['staff_person_id' => ['required', 'integer'], 'starts_at' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_at' => ['required', 'date_format:Y-m-d\TH:i'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $people->leave($b, $data, $request->user()->id);

        return back()->with('status', 'Availability / leave recorded.');
    }

    public function attendance(Request $request, PeopleOperations $people): RedirectResponse
    {
        $b = $this->manage($request);
        $data = $request->validate(['staff_person_id' => ['required', 'integer'], 'staff_shift_id' => ['nullable', 'integer'], 'clocked_in_at' => ['required', 'date_format:Y-m-d\TH:i'], 'clocked_out_at' => ['required', 'date_format:Y-m-d\TH:i'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $people->attendance($b, $data, $request->user()->id);

        return back()->with('status', 'Supervisor observation recorded; independent time review is still required.');
    }

    public function breakAction(Request $request, int $attendance, PeopleOperations $people): RedirectResponse
    {
        $b = $request->attributes->get('activeBusiness');
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'staff.clock'), 403);
        $data = $request->validate(['action' => ['required', Rule::in(['start', 'end'])]]);
        $entry = StaffAttendance::query()->where('business_id', $b->id)->where('user_id', $request->user()->id)->findOrFail($attendance);
        $people->breakAction($b, $entry, $data['action'], $request->user()->id);

        return back()->with('status', 'Break recorded with server time.');
    }

    public function correction(Request $request, int $attendance, PeopleOperations $people): RedirectResponse
    {
        $b = $request->attributes->get('activeBusiness');
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'staff.clock'), 403);
        $manager = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'staff.manage');
        $entry = StaffAttendance::query()->where('business_id', $b->id)->when(! $manager, fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($attendance);
        $data = $request->validate(['proposed_in_at' => ['required', 'date_format:Y-m-d\TH:i'], 'proposed_out_at' => ['required', 'date_format:Y-m-d\TH:i'], 'reason' => ['required', 'string', 'min:20', 'max:2000']]);
        $people->correction($b, $entry, $data, $request->user()->id);

        return back()->with('status', 'Correction requested. Original clock observations remain unchanged.');
    }

    public function review(Request $request, int $correction, PeopleOperations $people): RedirectResponse
    {
        $b = $this->manage($request);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['required', 'string', 'min:10', 'max:1000']]);
        $people->reviewCorrection($b, StaffAttendanceCorrection::query()->where('business_id', $b->id)->findOrFail($correction), $data['decision'], $data['note'], $request->user()->id);

        return back()->with('status', 'Attendance correction reviewed.');
    }

    public function approve(Request $request, int $attendance, PeopleOperations $people): RedirectResponse
    {
        $b = $this->manage($request);
        $data = $request->validate(['note' => ['required', 'string', 'min:10', 'max:1000']]);
        $people->approveTime($b, StaffAttendance::query()->where('business_id', $b->id)->findOrFail($attendance), $data['note'], $request->user()->id);

        return back()->with('status', 'Time approved for future wage preparation; no payroll or cash entry was created.');
    }
}
