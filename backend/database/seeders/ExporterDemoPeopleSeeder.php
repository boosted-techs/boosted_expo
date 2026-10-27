<?php

namespace Database\Seeders;

use App\Models\StaffAttendance;
use App\Models\StaffAttendanceCorrection;
use App\Models\StaffBreak;
use App\Models\StaffRota;
use App\Services\FinancePayrollCorrections;
use App\Services\FinancePayrollInputs;
use App\Services\FinancePayrollSettlements;
use App\Services\FinancePayrollStatutory;
use App\Services\FinanceTimePayroll;
use App\Services\PeopleOperations;
use App\Support\ExporterDemoContext;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ExporterDemoPeopleSeeder extends Seeder
{
    public function run(ExporterDemoContext $d): void
    {
        $b = $d->business;
        $hr = $d->id('hr');
        $owner = $d->id('owner');
        $people = app(PeopleOperations::class);
        $yesterday = $d->date(1);
        $time = fn ($date, $clock) => $date.'T'.$clock;
        $rota = StaffRota::create(['business_id' => $b->id, 'week_start' => now('Africa/Kampala')->subDay()->startOfWeek()->toDateString(), 'name' => 'DEMO export team rota', 'status' => 'draft', 'created_by' => $hr, 'request_key' => $d->key()]);
        foreach (['packing', 'warehouse', 'buyer'] as $role) {
            $shift = $people->shift($b, $rota, ['staff_person_id' => $d->get('staff_'.$role)->id, 'starts_at' => $time($yesterday, '08:00'), 'ends_at' => $time($yesterday, '17:00'), 'location' => 'DEMO Kampala packhouse', 'required_skill' => 'packing'], $hr);
            $d->put('shift_'.$role, $shift);
        }$people->publish($b, $rota, $hr);
        foreach (['packing', 'warehouse', 'buyer'] as $role) {
            $entry = $people->attendance($b, ['staff_person_id' => $d->get('staff_'.$role)->id, 'staff_shift_id' => $d->get('shift_'.$role)->id, 'clocked_in_at' => $time($yesterday, '08:00'), 'clocked_out_at' => $time($yesterday, '17:00'), 'reason' => $d->note('Illustrative completed supervisor-observed work.')], $hr);
            StaffBreak::create(['business_id' => $b->id, 'staff_attendance_id' => $entry->id, 'starts_at' => Carbon::parse($time($yesterday, '12:00'), 'Africa/Kampala')->utc(), 'ends_at' => Carbon::parse($time($yesterday, '12:30'), 'Africa/Kampala')->utc(), 'recorded_by' => $d->id($role)]);
            if ($role === 'packing') {
                $people->correction($b, $entry, ['proposed_in_at' => $time($yesterday, '08:05'), 'proposed_out_at' => $time($yesterday, '17:00'), 'reason' => $d->note('Correct an illustrative five-minute clock error.')], $hr);
                $people->reviewCorrection($b, StaffAttendanceCorrection::where('staff_attendance_id', $entry->id)->firstOrFail(), 'approve', $d->note('Independent review of the source observation.'), $owner);
            }$people->approveTime($b, $entry, $d->note('Independent review of completed hours and breaks.'), $owner);
        }
        $people->leave($b, ['staff_person_id' => $d->get('staff_sales')->id, 'starts_at' => $d->date(-7).'T08:00', 'ends_at' => $d->date(-7).'T17:00', 'reason' => $d->note('Example approved future leave.')], $hr);
        $open = StaffAttendance::create(['business_id' => $b->id, 'staff_person_id' => $d->get('staff_packing')->id, 'user_id' => $d->id('packing'), 'clocked_in_at' => now()->subHour(), 'recorded_by' => $d->id('packing'), 'work_note' => $d->note('Open attendance: still clocked in today, awaiting completion.')]);
        $people->breakAction($b, $open, 'start', $d->id('packing'));
        $statutory = app(FinancePayrollStatutory::class);
        $policy = $statutory->propose($b, ['name' => 'DEMO Uganda payroll policy · training only', 'template' => 'ug_2026_07', 'starts_on' => max('2026-07-01', $d->date(30)), 'ends_on' => $d->date(-365), 'source_note' => $d->note('Uses the application template for training. Obtain a current independent tax and NSSF review before real payroll.')], $hr);
        $statutory->approve($policy, $owner);
        $rates = app(FinanceTimePayroll::class);
        $inputs = app(FinancePayrollInputs::class);
        foreach (['packing' => ['hourly', '5000'], 'warehouse' => ['daily', '30000'], 'casual' => ['piece', '250']] as $role => [$basis,$amount]) {
            $person = $role === 'casual' ? $d->get('casual') : $d->get('staff_'.$role);
            $rate = $rates->proposeRate($b, ['staff_person_id' => $person->id, 'basis' => $basis, 'amount' => $amount, 'starts_on' => $d->date(10), 'ends_on' => $d->date(-30), 'regular_daily_hours' => '8', 'output_unit' => $basis === 'piece' ? 'kg' : null, 'work_code' => $basis === 'piece' ? 'grading' : null, 'agreement_reference' => $d->note('Illustrative agreed compensation; independent owner approval.')], $hr);
            $rates->approveRate($rate, $owner);
            if ($role === 'casual') {
                $output = $inputs->output($b, ['staff_pay_rate_id' => $rate->id, 'worked_on' => $d->date(), 'quantity' => '80', 'unit' => 'kg', 'work_code' => 'grading', 'packing_job_id' => $d->get('packing_job')->id, 'sales_order_id' => $d->get('order')->id, 'external_reference' => 'DEMO-PACKING-TALLY', 'evidence' => $d->note('80 kg completed packing tally, linked to the order.')], $hr);
                $inputs->review($b, 'output', $output->id, true, $d->note('Reviewed illustrative output separately.'), $owner);
            }
        }
        $adjust = $inputs->adjustment($b, ['staff_person_id' => $d->get('casual')->id, 'effective_on' => $d->date(), 'kind' => 'earning', 'amount' => '5000', 'taxable' => true, 'nssf_eligible' => false, 'external_reference' => 'DEMO-HANDLING-ALLOWANCE', 'evidence' => $d->note('Agreed extra handling work.')], $hr);
        $inputs->review($b, 'adjustment', $adjust->id, true, $d->note('Reviewed wage adjustment independently.'), $owner);
        $run = $inputs->prepare($b, ['period_from' => $yesterday, 'period_to' => $yesterday, 'staff_person_ids' => [$d->get('staff_packing')->id, $d->get('staff_warehouse')->id], 'statutory_policy_id' => $policy->id, 'notes' => $d->note('Approved completed work, breaks and corrections.')], $hr);
        $d->post('payroll_run', $run, $yesterday);
        $d->put('payroll', $run);
        $salary = $run->lines()->where('staff_person_id', $d->get('staff_packing')->id)->firstOrFail();
        $settlement = app(FinancePayrollSettlements::class)->prepare($b, $run->fresh(), ['kind' => 'salary', 'payroll_line_id' => $salary->id, 'amount' => (string) $salary->net, 'paid_on' => $d->date(), 'cash_account_key' => 'cash', 'external_reference' => 'DEMO-SALARY-RECEIPT', 'evidence_note' => $d->note('Synthetic salary receipt; no money moved.'), 'idempotency_key' => $d->key()], $d->id('accountant'));
        $d->post('payroll_settlement', $settlement);
        $casual = $inputs->prepare($b, ['period_from' => $d->date(), 'period_to' => $d->date(), 'staff_person_ids' => [$d->get('casual')->id], 'statutory_policy_id' => $policy->id, 'notes' => $d->note('Piece work and allowance awaiting a demonstrated correction.')], $hr);
        $d->post('payroll_run',$casual);
        $correction = app(FinancePayrollCorrections::class)->prepare($b,$casual->fresh(),$d->note('Example correction request held for independent review.'),$hr);
        $d->put('payroll_correction',$correction);
    }
}
