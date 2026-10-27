<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\CashCustody;
use App\Models\FinancePayrollRun;
use App\Models\FinancePayrollSettlement;
use App\Models\FinancePostingRequest;
use App\Models\FinanceWorkflow;
use App\Models\PayrollStatutoryPolicy;
use App\Models\StaffAttendance;
use App\Models\StaffLeave;
use App\Models\StaffOutput;
use App\Models\StaffPayAdjustment;
use App\Models\StaffPayRate;
use App\Models\StaffPerson;
use App\Models\User;
use App\Services\FinancePayrollSettlements;
use App\Services\FinanceSetup;
use App\Services\FinanceWorkflows;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayrollCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Payroll Exporters', 'code' => 'PAYCOMP', 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$owner, $reviewer] as $user) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true]);
        }app(FinanceSetup::class)->activate($business);
        $staff = StaffPerson::create(['business_id' => $business->id, 'staff_code' => 'PACKER', 'name' => 'Packing employee', 'kind' => 'employee', 'active' => true]);
        $this->actingAs($owner)->withSession(['active_business_id' => $business->id]);
        $this->post(route('finance.payroll.policy'), ['name' => 'Uganda July 2026 policy', 'template' => 'ug_2026_07', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'source_note' => 'URA September 2026 notice and NSSF source rates independently reviewed.'])->assertRedirect()->assertSessionHasNoErrors();
        $policy = PayrollStatutoryPolicy::firstOrFail();
        $this->post(route('finance.payroll.policy-approve', $policy->id))->assertStatus(422);
        $this->actingAs($reviewer)->post(route('finance.payroll.policy-approve', $policy->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('finance.payroll.classify', $staff->id), ['payroll_tax_class' => 'resident', 'nssf_applicable' => '1', 'reason' => 'Resident employment and NSSF membership were independently documented.'])->assertRedirect()->assertSessionHasNoErrors();

        return compact('business', 'owner', 'reviewer', 'staff', 'policy');
    }

    private function rate(array $c, string $basis, string $amount, array $extra = []): StaffPayRate
    {
        $this->actingAs($c['owner'])->post(route('finance.pay-rates.propose'), [...['staff_person_id' => $c['staff']->id, 'basis' => $basis, 'amount' => $amount, 'regular_daily_hours' => '8', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'agreement_reference' => 'Signed employment and pay agreement reference.'], ...$extra])->assertRedirect()->assertSessionHasNoErrors();
        $rate = StaffPayRate::query()->latest('id')->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.pay-rates.approve', $rate->id))->assertRedirect()->assertSessionHasNoErrors();

        return $rate;
    }

    private function prepare(array $c, string $from = '2026-09-01', string $to = '2026-09-30'): FinancePayrollRun
    {
        $this->actingAs($c['owner'])->post(route('finance.payroll.from-inputs'), ['period_from' => $from, 'period_to' => $to, 'staff_person_ids' => [$c['staff']->id], 'statutory_policy_id' => $c['policy']->id, 'notes' => 'Reviewed earned work, statutory classification and all approved wage sources.'])->assertRedirect()->assertSessionHasNoErrors();

        return FinancePayrollRun::query()->latest('id')->firstOrFail();
    }

    private function postRun(array $c, FinancePayrollRun $run): void
    {
        $this->actingAs($c['owner'])->post(route('finance.reviews.request', ['type' => 'payroll_run', 'source' => $run->id]), ['posting_on' => $run->period_to->toDateString(), 'basis_notes' => 'Verified payroll inputs, source claims and dated statutory policy.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('source_type', 'payroll_run')->where('source_id', $run->id)->firstOrFail();
        $this->post(route('finance.reviews.approve', $review->id), ['notes' => 'Own payroll preparation cannot be approved by its preparer.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independently checked the approved sources, tax and contribution components.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_monthly_pay_uses_the_new_resident_bands_and_keeps_employer_contributions_separate(): void
    {
        $c = $this->context();
        $this->rate($c, 'monthly', '1000000');
        $run = $this->prepare($c);
        $this->assertSame('1000000.00', $run->data_snapshot['gross']);
        $this->assertSame('188250.00', $run->data_snapshot['statutory_totals']['paye']);
        $this->assertSame('50000.00', $run->data_snapshot['statutory_totals']['nssf_employee']);
        $this->assertSame('100000.00', $run->data_snapshot['statutory_totals']['nssf_employer']);
        $this->assertSame('761750.00', $run->data_snapshot['net']);
        $this->postRun($c, $run);
        $run = $run->fresh();
        $this->assertTrue(app(FinancePayrollSettlements::class)->remaining($run, 'paye')->isEqualTo('188250'));
        $this->assertTrue(app(FinancePayrollSettlements::class)->remaining($run, 'nssf')->isEqualTo('150000'));
        $this->assertTrue(app(FinancePayrollSettlements::class)->remaining($run, 'withholding')->isZero());
        $this->get(route('finance.payroll.show', $run->id))->assertOk();
    }

    public function test_split_month_payroll_uses_month_to_date_tax_instead_of_repeating_the_tax_free_band(): void
    {
        $c = $this->context();
        $this->rate($c, 'monthly', '1000000');
        $first = $this->prepare($c, '2026-09-01', '2026-09-15');
        $this->assertSame('500000.00', $first->data_snapshot['gross']);
        $this->assertSame('38250.00', $first->data_snapshot['statutory_totals']['paye']);
        $this->postRun($c, $first);
        $second = $this->prepare($c, '2026-09-16', '2026-09-30');
        $this->assertSame('500000.00', $second->data_snapshot['gross']);
        $this->assertSame('150000.00', $second->data_snapshot['statutory_totals']['paye']);
        $this->assertSame('25000.00', $second->data_snapshot['statutory_totals']['nssf_employee']);
        $this->postRun($c, $second);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_overtime_uses_total_approved_work_on_a_day_and_piece_sources_are_reviewed_and_claimed_once(): void
    {
        $c = $this->context();
        $rate = $this->rate($c, 'hourly', '10000', ['overtime_multiplier' => '1.5']);
        foreach ([['08:00', '13:00'], ['13:30', '18:30']] as [$start,$end]) {
            StaffAttendance::create(['business_id' => $c['business']->id, 'staff_person_id' => $c['staff']->id, 'recorded_by' => $c['owner']->id, 'clocked_in_at' => Carbon::parse('2026-09-20 '.$start, 'Africa/Kampala')->utc(), 'clocked_out_at' => Carbon::parse('2026-09-20 '.$end, 'Africa/Kampala')->utc(), 'approved_by' => $c['reviewer']->id, 'approved_at' => now(), 'review_note' => 'Independently verified completed working time.']);
        }$run = $this->prepare($c);
        $this->assertSame('110000.00', $run->data_snapshot['gross']);
        $this->assertSame('104500.00', $run->data_snapshot['net']);
        $this->assertDatabaseCount('finance_payroll_input_claims', 2);
        $this->post(route('finance.payroll.withdraw', $run->id), ['reason' => 'A corrected wage source requires this unposted payroll to be withdrawn.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $run->fresh()->posting_status);
        $replacement = $this->prepare($c);
        $this->assertSame(2, $replacement->revision);
        $this->postRun($c, $replacement);
    }

    public function test_piece_work_and_adjustments_have_reviewed_sources_and_cannot_be_claimed_twice(): void
    {
        $c = $this->context();
        $rate = $this->rate($c, 'piece', '2000', ['output_unit' => 'kg', 'work_code' => 'grading']);
        $this->actingAs($c['owner'])->post(route('finance.payroll.output'), ['staff_pay_rate_id' => $rate->id, 'worked_on' => '2026-09-20', 'quantity' => '3', 'unit' => 'kg', 'work_code' => 'grading', 'external_reference' => 'OUTPUT-001', 'evidence' => 'Signed grading tally witnessed independently at the packhouse.'])->assertRedirect()->assertSessionHasNoErrors();
        $output = StaffOutput::firstOrFail();
        $this->post(route('finance.payroll.from-inputs'), ['period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'staff_person_ids' => [$c['staff']->id], 'statutory_policy_id' => $c['policy']->id, 'notes' => 'Unreviewed piece-work must not enter the wage ledger.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.payroll.input-review', ['output', $output->id, 'approve']), ['note' => 'Verified the physical output and agreed piece rate separately.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.payroll.adjustment'), ['staff_person_id' => $c['staff']->id, 'effective_on' => '2026-09-20', 'kind' => 'earning', 'amount' => '1000', 'taxable' => '0', 'nssf_eligible' => '0', 'external_reference' => 'ALLOWANCE-001', 'evidence' => 'Verified separate non-taxable reimbursement classification and agreement.'])->assertRedirect()->assertSessionHasNoErrors();
        $adjustment = StaffPayAdjustment::firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.payroll.input-review', ['adjustment', $adjustment->id, 'approve']), ['note' => 'Independently checked the allowance amount and tax treatment evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $run = $this->prepare($c);
        $this->assertSame('7000.00', $run->data_snapshot['gross']);
        $this->assertSame('6700.00', $run->data_snapshot['net']);
        $this->assertDatabaseCount('finance_payroll_input_claims', 2);
        $this->actingAs($c['reviewer'])->post(route('finance.payroll.input-withdraw', ['output', $output->id]), ['reason' => 'Claimed output cannot be withdrawn while its payroll is still active.'])->assertStatus(422);
        $this->postRun($c, $run);
    }

    public function test_unpaid_monthly_leave_requires_a_review_and_uses_the_agreed_salary_day_units(): void
    {
        $c = $this->context();
        $this->rate($c, 'monthly', '300000');
        $leave = StaffLeave::create(['business_id' => $c['business']->id, 'staff_person_id' => $c['staff']->id, 'starts_at' => Carbon::parse('2026-09-10 00:00', 'Africa/Kampala')->utc(), 'ends_at' => Carbon::parse('2026-09-11 00:00', 'Africa/Kampala')->utc(), 'reason' => 'Recorded absence awaiting a verified wage treatment.', 'status' => 'approved', 'recorded_by' => $c['owner']->id]);
        $this->actingAs($c['owner'])->post(route('finance.payroll.from-inputs'), ['period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'staff_person_ids' => [$c['staff']->id], 'statutory_policy_id' => $c['policy']->id, 'notes' => 'Unverified absence treatment must be resolved before payroll.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.payroll.absence', ['leave', $leave->id]), ['pay_treatment' => 'unpaid', 'unpaid_day_units' => '1', 'reason' => 'One unpaid salary day confirmed under the employment agreement.'])->assertRedirect()->assertSessionHasNoErrors();
        $run = $this->prepare($c);
        $this->assertSame('290000.00', $run->data_snapshot['gross']);
        $this->assertSame('275500.00', $run->data_snapshot['net']);
        $this->postRun($c, $run);
    }

    public function test_different_staff_can_have_payrolls_in_the_same_period_without_paying_one_employee_twice(): void
    {
        $c = $this->context();
        $this->rate($c, 'monthly', '1000000');
        $first = $this->prepare($c);
        $this->postRun($c, $first);
        $second = StaffPerson::create(['business_id' => $c['business']->id, 'staff_code' => 'OTHER', 'name' => 'Other employee', 'kind' => 'employee', 'active' => true]);
        $other = [...$c, 'staff' => $second];
        $this->actingAs($c['owner'])->post(route('finance.payroll.classify', $second->id), ['payroll_tax_class' => 'resident', 'nssf_applicable' => '1', 'reason' => 'Verified resident employee with registered NSSF membership.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->rate($other, 'monthly', '300000');
        $secondRun = $this->prepare($other);
        $this->postRun($other, $secondRun);
        $this->assertSame(2, $secondRun->revision);
        $this->actingAs($c['owner'])->post(route('finance.payroll.from-inputs'), ['period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'staff_person_ids' => [$c['staff']->id], 'statutory_policy_id' => $c['policy']->id, 'notes' => 'The original employee cannot receive a duplicate payroll for the same dates.'])->assertSessionHasErrors('period_from');
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_statutory_remittance_caps_and_named_cash_float_are_enforced(): void
    {
        $c = $this->context();
        $this->rate($c, 'monthly', '1000000');
        $run = $this->prepare($c);
        $this->postRun($c, $run);
        $this->actingAs($c['owner'])->post(route('finance.cash.custody'), ['name' => 'Payroll cash', 'currency' => 'UGX', 'staff_person_id' => $c['staff']->id, 'authorised_float' => '2000000'])->assertRedirect()->assertSessionHasNoErrors();
        $custody = CashCustody::firstOrFail();
        $this->post(route('finance.treasury.store'), ['kind' => 'owner_contribution', 'currency' => 'UGX', 'amount' => '1000000', 'cash_custody_id' => $custody->id, 'event_on' => '2026-10-27', 'reason' => 'Actual owner funding handed to the payroll cash custodian.', 'external_reference' => 'PAY-FLOAT-FUND', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $flow = FinanceWorkflow::firstOrFail();
        $this->post(route('finance.reviews.request', ['type' => 'finance_workflow', 'source' => $flow->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Independent signed owner funding and custody handover evidence.'])->assertRedirect();
        $review = FinancePostingRequest::where('source_type', 'finance_workflow')->where('source_id', $flow->id)->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Verified actual cash funding and custodian receipt.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
        $data = ['kind' => 'nssf', 'recipient_name' => 'National Social Security Fund', 'amount' => '150000', 'paid_on' => '2026-10-27', 'cash_account_key' => 'cash', 'cash_custody_id' => $custody->id, 'external_reference' => 'NSSF-REMIT-001', 'evidence_note' => 'Signed cash voucher and actual authority receipt independently verified.', 'idempotency_key' => (string) Str::uuid()];
        $this->post(route('finance.payroll.settlements.store', $run->id), [...$data, 'amount' => '150001'])->assertSessionHasErrors('amount');
        $this->post(route('finance.payroll.settlements.store', $run->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $payment = FinancePayrollSettlement::firstOrFail();
        $this->assertSame($custody->account_id, $payment->cash_account_id);
        $this->post(route('finance.reviews.request', ['type' => 'payroll_settlement', 'source' => $payment->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Verified NSSF remittance and actual recipient receipt.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::where('source_type', 'payroll_settlement')->where('source_id', $payment->id)->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Verified statutory cap and actual cash payment receipt.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(FinancePayrollSettlements::class)->remaining($run->fresh(), 'nssf')->isZero());
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['business']->id, $custody->account_id, '2026-10-27')['units']->isEqualTo('850000'));
    }
}
