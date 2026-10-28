<?php

namespace App\Http\Controllers;

use App\Models\BusinessMembership;
use App\Models\StaffAttendance;
use App\Models\StaffShift;
use App\Services\StaffOperations;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffController extends Controller
{
    private function access(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $request): View
    {
        $this->access($request, 'staff.clock');
        $business = $request->attributes->get('activeBusiness');
        $member = $request->attributes->get('membership');
        $canManage = app(BusinessPermissions::class)->allows($member, 'staff.manage');
        $activity = $request->route('activity', 'clock');
        $activities = ['clock' => ['My attendance', 'Clock in when you begin work and clock out when you finish.'], 'shifts' => ['Staff shifts', 'Review scheduled and completed shifts.'], 'schedule' => ['Schedule a staff shift', 'Assign a team member to a working period.'], 'history' => ['Attendance history', 'Review recorded working hours.']];
        abort_unless(isset($activities[$activity]), 404);
        abort_if($activity === 'schedule' && ! $canManage, 403);
        [$activityTitle,$activityDescription] = $activities[$activity];
        $staff = $canManage ? BusinessMembership::query()
            ->where('business_id', $business->id)->where('active', true)
            ->with('user')->orderBy('id')->get() : collect();
        $shifts = StaffShift::query()->where('business_id', $business->id)
            ->when(! $canManage, fn ($q) => $q->where('user_id', $request->user()->id)->whereNotNull('published_at'))
            ->whereNotNull('user_id')->with('user')->withCount('attendances')->orderByDesc('starts_at')->workspacePaginate(25, ['*'], 'shifts_page');
        $attendance = StaffAttendance::query()->where('business_id', $business->id)
            ->when(! $canManage, fn ($q) => $q->where('user_id', $request->user()->id))
            ->whereNotNull('user_id')->with(['user', 'shift'])->orderByDesc('clocked_in_at')->workspacePaginate(25, ['*'], 'attendance_page');
        $current = StaffAttendance::query()->where('business_id', $business->id)
            ->where('user_id', $request->user()->id)->whereNull('clocked_out_at')->whereNull('effective_out_at')->first();
        $myUpcoming = StaffShift::query()->where('business_id', $business->id)
            ->where('user_id', $request->user()->id)->whereNotNull('published_at')->where('status', 'scheduled')
            ->where('ends_at', '>=', now()->subHours(4))
            ->orderBy('starts_at')->limit(20)->get();

        return view('staff.index', compact(
            'activity', 'activityTitle', 'activityDescription', 'business', 'staff', 'shifts', 'attendance', 'current', 'myUpcoming', 'canManage'));
    }

    public function schedule(Request $request, StaffOperations $service): RedirectResponse
    {
        $this->access($request, 'staff.manage');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'location' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $service->schedule($request->attributes->get('activeBusiness'),
            $data, $request->user()->id);

        return back()->with('status', 'Staff shift scheduled in the company timezone.');
    }

    public function cancel(Request $request, int $shift, StaffOperations $service): RedirectResponse
    {
        $this->access($request, 'staff.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $service->cancel($request->attributes->get('activeBusiness'),
            $shift, $request->user()->id, $data['reason']);

        return back()->with('status', 'Unused shift cancelled with an audit reason.');
    }

    public function clockIn(Request $request, StaffOperations $service): RedirectResponse
    {
        $this->access($request, 'staff.clock');
        $data = $request->validate(['shift_id' => ['nullable', 'integer', 'min:1']]);
        $service->clockIn($request->attributes->get('activeBusiness'),
            $request->user()->id, $data['shift_id'] ?? null);

        return back()->with('status', 'Clock-in recorded with the server time.');
    }

    public function clockOut(Request $request, int $attendance, StaffOperations $service): RedirectResponse
    {
        $this->access($request, 'staff.clock');
        $data = $request->validate(['work_note' => ['nullable', 'string', 'max:500']]);
        $service->clockOut($request->attributes->get('activeBusiness'),
            $request->user()->id, $attendance, $data['work_note'] ?? null);

        return back()->with('status', 'Clock-out recorded. Attendance does not automatically post payroll.');
    }
}
