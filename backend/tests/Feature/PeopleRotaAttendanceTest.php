<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceCorrection;
use App\Models\StaffBreak;
use App\Models\StaffPerson;
use App\Models\StaffRota;
use App\Models\StaffShift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PeopleRotaAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
    }

    private function context(string $code = 'KLA'): array
    {
        $b = Business::create(['name' => $code.' Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        $hr = User::factory()->create();
        $worker = User::factory()->create();
        foreach ([[$owner, 'owner'], [$hr, 'hr'], [$worker, 'packing']] as [$u,$role]) {
            BusinessMembership::create(['business_id' => $b->id, 'user_id' => $u->id, 'role' => $role, 'active' => true]);
        }$person = StaffPerson::create(['business_id' => $b->id, 'user_id' => $worker->id, 'staff_code' => 'WORKER', 'name' => 'Packing Worker', 'skills' => ['packing']]);
        $casual = StaffPerson::create(['business_id' => $b->id, 'staff_code' => 'CASUAL', 'name' => 'Casual Grader', 'kind' => 'casual', 'skills' => ['grading', 'packing']]);

        return compact('b', 'owner', 'hr', 'worker', 'person', 'casual');
    }

    private function as(array $c, string $actor = 'owner'): self
    {
        return $this->actingAs($c[$actor])->withSession(['active_business_id' => $c['b']->id]);
    }

    private function rota(array $c): StaffRota
    {
        $this->as($c)->post(route('people.rotas.store'), ['name' => 'Export packhouse week', 'week_start' => '2026-10-26', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        return StaffRota::latest('id')->firstOrFail();
    }

    private function shift(array $c, StaffRota $r, ?StaffPerson $person = null): StaffShift
    {
        $this->as($c)->post(route('people.shifts.store', $r), ['staff_person_id' => ($person ?? $c['person'])->id, 'starts_at' => '2026-10-28T22:00', 'ends_at' => '2026-10-29T06:00', 'location' => 'Packhouse', 'required_skill' => 'packing'])->assertRedirect()->assertSessionHasNoErrors();

        return StaffShift::latest('id')->firstOrFail();
    }

    public function test_casual_worker_has_no_login_seat_and_overnight_published_rota_preserves_kampala_time(): void
    {
        $c = $this->context();
        $r = $this->rota($c);
        $shift = $this->shift($c, $r, $c['casual']);
        $this->assertNull($shift->user_id);
        $this->assertSame('2026-10-28 19:00:00', $shift->starts_at->format('Y-m-d H:i:s'));
        $this->post(route('people.rotas.publish', $r))->assertSessionHasNoErrors();
        $this->assertNotNull($shift->fresh()->published_at);
        $this->assertDatabaseCount('business_memberships', 3);
        $this->assertDatabaseCount('users', 3);
        $this->get(route('people.activity', 'weekly'))->assertOk()->assertSee('Casual Grader')->assertSee('22:00');
    }

    public function test_staff_cannot_see_unpublished_rota_or_other_workers_personal_attendance(): void
    {
        $c = $this->context();
        $r = $this->rota($c);
        $shift = $this->shift($c, $r);
        $this->as($c, 'worker')->get(route('people.activity', 'weekly'))->assertOk()->assertDontSee('Casual Grader')->assertDontSee('22:00');
        $this->as($c)->post(route('people.rotas.publish', $r))->assertSessionHasNoErrors();
        $this->as($c, 'worker')->get(route('people.activity', 'weekly'))->assertOk()->assertSee('22:00')->assertDontSee('Casual Grader');
        $this->post(route('people.register'), ['name' => 'Unauthorized staff', 'staff_code' => 'BAD', 'kind' => 'employee'])->assertForbidden();
    }

    public function test_overlap_skill_and_leave_guards_are_rechecked_before_publication(): void
    {
        $c = $this->context();
        $r = $this->rota($c);
        $shift = $this->shift($c, $r);
        $payload = ['staff_person_id' => $c['person']->id, 'starts_at' => '2026-10-28T23:00', 'ends_at' => '2026-10-29T05:00', 'location' => 'Packhouse'];
        $this->post(route('people.shifts.store', $r), $payload)->assertSessionHasErrors('starts_at');
        $this->post(route('people.shifts.store', $r), [...$payload, 'starts_at' => '2026-10-29T08:00', 'ends_at' => '2026-10-29T12:00', 'required_skill' => 'driving'])->assertSessionHasErrors('required_skill');
        $this->post(route('people.leave'), ['staff_person_id' => $c['person']->id, 'starts_at' => '2026-10-28T00:00', 'ends_at' => '2026-10-30T00:00', 'reason' => 'Approved family leave request'])->assertSessionHasErrors('starts_at');
        $this->post(route('people.shifts.replace', $shift), ['staff_person_id' => $c['casual']->id, 'reason' => 'Worker on leave; qualified casual replacement'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $shift->fresh()->status);
        $this->post(route('people.leave'), ['staff_person_id' => $c['person']->id, 'starts_at' => '2026-10-28T00:00', 'ends_at' => '2026-10-30T00:00', 'reason' => 'Approved family leave request'])->assertSessionHasNoErrors();
        $this->post(route('people.shifts.store', $r), $payload)->assertSessionHasErrors('starts_at');
    }

    public function test_coverage_shortfall_blocks_publication_until_required_staff_are_assigned(): void
    {
        $c = $this->context();
        $r = $this->rota($c);
        $this->shift($c, $r);
        $this->post(route('people.rotas.coverage', $r), ['starts_at' => '2026-10-28T22:00', 'ends_at' => '2026-10-29T06:00', 'location' => 'Packhouse', 'required_skill' => 'packing', 'required_people' => 2])->assertSessionHasNoErrors();
        $this->post(route('people.rotas.publish', $r))->assertSessionHasErrors('coverage');
        $this->assertSame('draft', $r->fresh()->status);
        $this->shift($c, $r, $c['casual']);
        $this->post(route('people.rotas.publish', $r))->assertSessionHasNoErrors();
        $this->assertSame('published', $r->fresh()->status);
    }

    public function test_copying_a_weekly_rota_creates_unpublished_shifts_with_new_dates(): void
    {
        $c = $this->context();
        $r = $this->rota($c);
        $old = $this->shift($c, $r);
        $this->post(route('people.rotas.publish', $r))->assertSessionHasNoErrors();
        $this->post(route('people.rotas.store'), ['name' => 'Reused rota', 'week_start' => '2026-11-02', 'copy_rota_id' => $r->id, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $new = StaffShift::latest('id')->first();
        $this->assertNotSame($old->id, $new->id);
        $this->assertNull($new->published_at);
        $this->assertSame('2026-11-04', $new->starts_at->timezone('Africa/Kampala')->toDateString());
    }

    public function test_server_timed_break_must_end_before_clockout_and_other_worker_cannot_change_it(): void
    {
        $c = $this->context();
        $this->as($c, 'worker')->post(route('staff.clock-in'))->assertSessionHasNoErrors();
        $entry = StaffAttendance::firstOrFail();
        $this->post(route('people.break', $entry), ['action' => 'start'])->assertSessionHasNoErrors();
        $this->post(route('people.break', $entry), ['action' => 'start'])->assertStatus(422);
        $this->post(route('staff.clock-out', $entry))->assertStatus(422);
        $this->as($c, 'hr')->post(route('people.break', $entry), ['action' => 'end'])->assertNotFound();
        $this->travel(30)->minutes();
        $this->as($c, 'worker')->post(route('people.break', $entry), ['action' => 'end'])->assertSessionHasNoErrors();
        $this->post(route('staff.clock-out', $entry))->assertSessionHasNoErrors();
        $this->assertNotNull(StaffBreak::first()->ends_at);
        $this->assertNotNull($entry->fresh()->clocked_out_at);
    }

    public function test_supervisor_observation_needs_independent_time_approval_and_never_posts_payroll(): void
    {
        $c = $this->context();
        $this->as($c, 'hr')->post(route('people.attendance'), ['staff_person_id' => $c['casual']->id, 'clocked_in_at' => '2026-10-27T08:00', 'clocked_out_at' => '2026-10-27T12:00', 'reason' => 'Supervisor signed casual grading attendance'])->assertSessionHasNoErrors();
        $entry = StaffAttendance::firstOrFail();
        $this->assertNull($entry->user_id);
        $this->post(route('people.attendance.approve', $entry), ['note' => 'I recorded and try to approve the same time'])->assertForbidden();
        $this->as($c)->post(route('people.attendance.approve', $entry), ['note' => 'Independent supervisor evidence verified'])->assertSessionHasNoErrors();
        $this->assertNotNull($entry->fresh()->approved_at);
        $this->assertDatabaseCount('finance_payroll_runs', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_missed_clockout_correction_preserves_observation_and_allows_next_attendance_after_review(): void
    {
        $c = $this->context();
        $this->as($c, 'worker')->post(route('staff.clock-in'))->assertSessionHasNoErrors();
        $entry = StaffAttendance::firstOrFail();
        $original = $entry->clocked_in_at->toIso8601String();
        $this->travel(1)->hours();
        $this->post(route('people.correction', $entry), ['proposed_in_at' => '2026-10-27T15:00', 'proposed_out_at' => '2026-10-27T16:00', 'reason' => 'Forgot to clock out; supervisor verifies ending time'])->assertSessionHasNoErrors();
        $correction = StaffAttendanceCorrection::firstOrFail();
        $this->as($c, 'hr')->post(route('people.correction.review', $correction), ['decision' => 'approve', 'note' => 'Independent signed attendance sheet checked'])->assertSessionHasNoErrors();
        $this->assertNull($entry->fresh()->clocked_out_at);
        $this->assertSame($original, $entry->fresh()->clocked_in_at->toIso8601String());
        $this->assertNotNull($entry->fresh()->effective_out_at);
        $this->as($c, 'worker')->post(route('staff.clock-in'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('staff_attendances', 2);
    }

    public function test_self_review_unresolved_clockout_and_approved_time_changes_are_refused(): void
    {
        $c = $this->context();
        $this->as($c, 'worker')->post(route('staff.clock-in'))->assertSessionHasNoErrors();
        $entry = StaffAttendance::firstOrFail();
        $this->as($c, 'hr')->post(route('people.attendance.approve', $entry), ['note' => 'Missing clock out must not silently become payable'])->assertStatus(422);
        $this->travel(1)->hours();
        $this->as($c)->post(route('people.correction', $entry), ['proposed_in_at' => '2026-10-27T15:00', 'proposed_out_at' => '2026-10-27T16:00', 'reason' => 'Supervisor records missed clockout with source evidence'])->assertSessionHasNoErrors();
        $correction = StaffAttendanceCorrection::firstOrFail();
        $this->post(route('people.correction.review', $correction), ['decision' => 'approve', 'note' => 'Cannot approve my own submitted correction'])->assertForbidden();
        $this->as($c, 'hr')->post(route('people.correction.review', $correction), ['decision' => 'approve', 'note' => 'Independent source record checked'])->assertSessionHasNoErrors();
        $this->post(route('people.attendance.approve', $entry), ['note' => 'Resolved time independently verified'])->assertSessionHasNoErrors();
        $this->as($c, 'worker')->post(route('people.correction', $entry), ['proposed_in_at' => '2026-10-27T15:00', 'proposed_out_at' => '2026-10-27T16:00', 'reason' => 'Approved attendance must remain protected'])->assertStatus(422);
    }

    public function test_people_shift_leave_and_attendance_identifiers_cannot_cross_tenants(): void
    {
        $a = $this->context('A');
        $b = $this->context('B');
        $r = $this->rota($b);
        $this->as($a)->post(route('people.shifts.store', $r), ['staff_person_id' => $a['person']->id, 'starts_at' => '2026-10-28T08:00', 'ends_at' => '2026-10-28T16:00'])->assertNotFound();
        $this->post(route('people.leave'), ['staff_person_id' => $b['person']->id, 'starts_at' => '2026-10-28T08:00', 'ends_at' => '2026-10-28T16:00', 'reason' => 'Wrong tenant must not approve leave'])->assertNotFound();
        $this->get(route('people.index'))->assertOk()->assertViewHas('people', fn ($rows) => $rows->every(fn ($row) => $row->business_id === $a['b']->id));
    }

    public function test_elapsed_shift_absence_is_explicit_and_creates_no_fake_attendance(): void
    {
        $c = $this->context();
        $rota = $this->rota($c);
        $shift = $this->shift($c, $rota);
        $this->post(route('people.rotas.publish', $rota))->assertSessionHasNoErrors();
        $this->post(route('people.shifts.absence', $shift), ['reason' => 'Future shift cannot be marked absent early'])->assertStatus(422);
        $this->travel(3)->days();
        $this->post(route('people.shifts.absence', $shift), ['reason' => 'Supervisor confirmed employee did not report for duty'])->assertSessionHasNoErrors();
        $this->assertSame('absent', $shift->fresh()->status);
        $this->assertDatabaseCount('staff_attendances', 0);
    }
}
