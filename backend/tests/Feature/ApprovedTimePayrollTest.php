<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\FinancePayrollRun;
use App\Models\FinancePostingRequest;
use App\Models\StaffAttendance;
use App\Models\StaffBreak;
use App\Models\StaffPayRate;
use App\Models\StaffPerson;
use App\Models\User;
use App\Services\FinanceSetup;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ApprovedTimePayrollTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Exporter', 'code' => 'TIME', 'base_currency' => 'UGX', 'country_code' => 'UG', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$owner, $reviewer] as $user) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true]);
        }
        app(FinanceSetup::class)->activate($business);
        $person = StaffPerson::create(['business_id' => $business->id, 'name' => 'Casual worker', 'staff_code' => 'CASUAL', 'kind' => 'casual', 'active' => true]);
        $entry = StaffAttendance::create(['business_id' => $business->id, 'staff_person_id' => $person->id, 'clocked_in_at' => '2026-10-26 05:00:00', 'clocked_out_at' => '2026-10-26 13:00:00', 'recorded_by' => $owner->id, 'approved_by' => $reviewer->id, 'approved_at' => now()]);
        StaffBreak::create(['business_id' => $business->id, 'staff_attendance_id' => $entry->id, 'starts_at' => '2026-10-26 08:00:00', 'ends_at' => '2026-10-26 08:30:00', 'recorded_by' => $owner->id]);

        return compact('business', 'owner', 'reviewer', 'person', 'entry');
    }

    private function rate(array $c, string $basis = 'hourly'): StaffPayRate
    {
        $this->actingAs($c['owner'])->post(route('finance.pay-rates.propose'), ['staff_person_id' => $c['person']->id, 'basis' => $basis, 'amount' => '2000', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'agreement_reference' => 'Signed worker pay agreement 2026'])->assertRedirect()->assertSessionHasNoErrors();
        $rate = StaffPayRate::firstOrFail();
        $this->post(route('finance.pay-rates.approve', $rate->id))->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.pay-rates.approve', $rate->id))->assertRedirect()->assertSessionHasNoErrors();

        return $rate;
    }

    private function data(array $c): array
    {
        return ['period_from' => '2026-10-26', 'period_to' => '2026-10-26', 'staff_person_ids' => [$c['person']->id], 'notes' => 'Prepared using approved attendance and pay agreement.'];
    }

    public function test_casual_worker_pay_uses_approved_hours_less_breaks_and_existing_approval_and_settlement_records(): void
    {
        $c = $this->context();
        $this->rate($c);
        $this->actingAs($c['owner'])->post(route('finance.payroll.from-time'), $this->data($c))->assertRedirect()->assertSessionHasNoErrors();
        $run = FinancePayrollRun::firstOrFail();
        $line = $run->lines()->firstOrFail();
        $this->assertNull($line->user_id);
        $this->assertSame('Casual worker', $line->name_snapshot);
        $this->assertTrue(BigDecimal::of((string) $line->gross)->isEqualTo('15000'));
        $this->assertDatabaseCount('finance_payroll_time_claims', 1);
        $this->post(route('finance.reviews.request', ['type' => 'payroll_run', 'source' => $run->id]), ['posting_on' => '2026-10-26', 'basis_notes' => 'Approved source times, worker contract and manual deductions checked.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independent payroll review completed.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('finance.payroll.show', $run->id))->assertOk()->assertSee('Casual worker');
        $this->get(route('finance.payroll.payslip', [$run->id, $line->id]))->assertOk()->assertSee('15000');
        $this->post(route('finance.payroll.from-time'), $this->data($c))->assertStatus(422);
        $this->assertDatabaseCount('finance_payroll_runs', 1);
        $this->get(route('finance.payroll.index'))->assertOk()->assertSee('Agreed daily / hourly');
    }

    public function test_unapproved_attendance_cannot_create_payroll_and_daily_rate_is_not_multiplied_by_two_clock_sessions(): void
    {
        $c = $this->context();
        $this->rate($c, 'daily');
        $c['entry']->update(['approved_at' => null]);
        $this->actingAs($c['owner'])->post(route('finance.payroll.from-time'), $this->data($c))->assertStatus(422);
        $this->assertDatabaseCount('finance_payroll_runs', 0);
        $c['entry']->update(['approved_at' => now()]);
        StaffAttendance::create(['business_id' => $c['business']->id, 'staff_person_id' => $c['person']->id, 'clocked_in_at' => '2026-10-26 14:00:00', 'clocked_out_at' => '2026-10-26 15:00:00', 'recorded_by' => $c['owner']->id, 'approved_by' => $c['reviewer']->id, 'approved_at' => now()]);
        $this->post(route('finance.payroll.from-time'), $this->data($c))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(BigDecimal::of((string) FinancePayrollRun::firstOrFail()->gross_total)->isEqualTo('2000'));
        $this->assertDatabaseCount('finance_payroll_time_claims',2);
    }
}
