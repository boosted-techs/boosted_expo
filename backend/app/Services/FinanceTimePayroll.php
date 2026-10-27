<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinancePayrollRun;
use App\Models\StaffAttendance;
use App\Models\StaffBreak;
use App\Models\StaffPayRate;
use App\Models\StaffPerson;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceTimePayroll
{
    public function proposeRate(Business $business, array $data, int $actor): StaffPayRate
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            app(FinancePeriodControls::class)->assertWritable($business, $data['starts_on']);
            $person = StaffPerson::query()->where('business_id', $business->id)->where('active', true)->findOrFail($data['staff_person_id']);
            abort_unless(in_array($data['basis'], ['hourly', 'daily', 'monthly', 'piece'], true) && $data['starts_on'] <= $data['ends_on'] && BigDecimal::of($data['amount'])->isGreaterThan(0), 422, 'Enter a positive agreed pay rate and a valid effective period.');

            if ($data['basis'] === 'piece') {
                abort_unless(! empty($data['output_unit']) && ! empty($data['work_code']), 422, 'A piece agreement needs its unit and work type.');
            }
            if ($data['basis'] === 'monthly' && ! empty($data['overtime_multiplier'])) {
                abort_unless(! empty($data['overtime_hourly_amount']), 422, 'Record the agreed monthly overtime hourly equivalent.');
            }
            $data['regular_daily_hours'] = $data['regular_daily_hours'] ?? 8;

            return StaffPayRate::create([...$data, 'staff_person_id' => $person->id, 'business_id' => $business->id, 'currency' => $business->base_currency, 'created_by' => $actor, 'status' => 'proposed']);
        }, 3);
    }

    public function approveRate(StaffPayRate $rate, int $actor): void
    {
        DB::transaction(function () use ($rate, $actor) {
            app(OrderOperations::class)->lockForWork($rate->business_id, 'finance');
            $rate = StaffPayRate::query()->where('business_id', $rate->business_id)->lockForUpdate()->findOrFail($rate->id);
            abort_unless($rate->status === 'proposed' && $rate->created_by !== $actor, 422, 'An independent reviewer must approve a proposed pay agreement.');
            if ($rate->supersedes_rate_id) {
                $old = StaffPayRate::query()->where('business_id', $rate->business_id)->where('staff_person_id', $rate->staff_person_id)->where('status', 'approved')->whereNull('superseded_on')->lockForUpdate()->findOrFail($rate->supersedes_rate_id);
                abort_unless($old->starts_on->lte($rate->starts_on), 422, 'A replacement agreement cannot start before the original.');
                abort_if(FinancePayrollRun::query()->where('business_id', $rate->business_id)->whereNotIn('posting_status', ['withdrawn', 'reversed'])->whereDate('period_to', '>=', $rate->starts_on)->whereHas('lines', fn ($q) => $q->where('staff_person_id', $rate->staff_person_id))->exists(), 422, 'This rate period already belongs to a payroll. Withdraw or formally correct the affected run first.');
                $old->update(['superseded_on' => $rate->starts_on]);
                app(PlatformAccess::class)->audit($rate->business_id, $actor, 'payroll.rate_superseded', $rate->agreement_reference, [], ['old_rate_id' => $old->id, 'new_rate_id' => $rate->id, 'effective_on' => $rate->starts_on->toDateString()]);
            }
            abort_if(StaffPayRate::query()->where('business_id', $rate->business_id)->where('staff_person_id', $rate->staff_person_id)->where('status', 'approved')->whereDate('starts_on', '<=', $rate->ends_on)->whereDate('ends_on', '>=', $rate->starts_on)->where(fn ($q) => $q->whereNull('superseded_on')->orWhereDate('superseded_on', '>', $rate->starts_on))->exists(), 422, 'An approved agreement already covers these dates. Effective periods must not overlap.');
            $rate->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now()]);
        }, 3);
    }

    public function prepare(Business $business, array $data, int $actor): FinancePayrollRun
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $from = Carbon::parse($data['period_from'], 'Africa/Kampala')->startOfDay()->utc();
            $to = Carbon::parse($data['period_to'], 'Africa/Kampala')->endOfDay()->utc();
            abort_unless($from->lte($to), 422, 'Choose a valid payroll period.');
            $lines = [];
            $claims = [];
            foreach (array_unique($data['staff_person_ids']) as $id) {
                $person = StaffPerson::query()->where('business_id', $business->id)->findOrFail($id);
                $entries = StaffAttendance::query()->where('business_id', $business->id)->where('staff_person_id', $person->id)
                    ->whereRaw('COALESCE(effective_in_at,clocked_in_at) >= ?', [$from->format('Y-m-d H:i:s')])
                    ->whereRaw('COALESCE(effective_in_at,clocked_in_at) <= ?', [$to->format('Y-m-d H:i:s')])->orderBy('clocked_in_at')->lockForUpdate()->get();
                abort_unless($entries->isNotEmpty(), 422, 'No attendance is available for '.$person->name.' in this period.');
                $total = BigDecimal::zero();
                $sources = [];
                $days = [];
                foreach ($entries as $entry) {
                    $start = $entry->effective_in_at ?? $entry->clocked_in_at;
                    $end = $entry->effective_out_at ?? $entry->clocked_out_at;
                    abort_unless($entry->approved_at && $end && $end->gt($start) && $end->lte($to), 422, 'Resolve and approve all selected attendance, including period-crossing shifts, before payroll.');
                    abort_if(DB::table('finance_payroll_time_claims as claim')->join('finance_payroll_runs as run', 'run.id', '=', 'claim.payroll_run_id')->where('claim.business_id', $business->id)->where('claim.staff_attendance_id', $entry->id)->whereNotIn('run.posting_status', ['reversed', 'withdrawn'])->exists(), 422, 'This attendance already belongs to a payroll.');
                    abort_if(DB::table('finance_payroll_input_claims as claim')->join('finance_payroll_runs as run', 'run.id', '=', 'claim.payroll_run_id')->where('claim.business_id', $business->id)->where('claim.source_type', 'attendance')->where('claim.source_id', $entry->id)->whereNotIn('run.posting_status', ['reversed', 'withdrawn'])->exists(), 422, 'This attendance already belongs to a complete-input payroll.');
                    $date = $start->copy()->timezone('Africa/Kampala')->toDateString();
                    $rate = StaffPayRate::query()->where('business_id', $business->id)->where('staff_person_id', $person->id)->effectiveOn($date)->get();
                    abort_unless($rate->count() === 1, 422, 'One approved pay agreement must cover '.$person->name.' on '.$date.'.');
                    $rate = $rate->first();
                    abort_unless(in_array($rate->basis, ['hourly', 'daily'], true) && ! $rate->overtime_multiplier, 422, 'Use the complete approved-input payroll task for monthly, piece or overtime agreements.');
                    $seconds = $end->getTimestamp() - $start->getTimestamp();
                    $breaks = [];
                    foreach (StaffBreak::query()->where('business_id', $business->id)->where('staff_attendance_id', $entry->id)->orderBy('starts_at')->get() as $break) {
                        abort_unless($break->ends_at, 422, 'Resolve every open break before payroll.');
                        $seconds -= max(0, min($end->getTimestamp(), $break->ends_at->getTimestamp()) - max($start->getTimestamp(), $break->starts_at->getTimestamp()));
                        $breaks[] = ['id' => $break->id, 'starts_at' => $break->starts_at->toIso8601String(), 'ends_at' => $break->ends_at->toIso8601String()];
                    }
                    abort_unless($seconds > 0, 422, 'Approved attendance has no payable time after breaks.');
                    $units = $rate->basis === 'hourly' ? BigDecimal::of($seconds)->dividedBy(3600, 12, RoundingMode::HalfUp) : BigDecimal::of(isset($days[$date]) ? 0 : 1);
                    $days[$date] = true;
                    $earnings = app(FinanceMath::class)->money($units->multipliedBy((string) $rate->amount));
                    $total = $total->plus($earnings);
                    $source = ['attendance_id' => $entry->id, 'date' => $date, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'approved_by' => $entry->approved_by, 'approved_at' => $entry->approved_at->toIso8601String(), 'breaks' => $breaks, 'payable_seconds' => $seconds, 'basis' => $rate->basis, 'rate_id' => $rate->id, 'rate_amount' => (string) $rate->amount, 'agreement_reference' => $rate->agreement_reference, 'units' => (string) $units, 'gross' => (string) $earnings];
                    $sources[] = $source;
                    $claims[] = $source;
                }
                $deduction = (string) ($data['deductions'][$person->id] ?? '0');
                $lines[] = ['staff_person_id' => $person->id, 'gross' => (string) $total, 'deductions' => $deduction, 'source_snapshot' => ['method' => 'approved_time_and_agreed_rate', 'entries' => $sources]];
            }
            $run = app(FinancePayroll::class)->prepare($business, [...$data, 'lines' => $lines], $actor);
            foreach ($claims as $claim) {
                DB::table('finance_payroll_time_claims')->insert(['business_id' => $business->id, 'payroll_run_id' => $run->id, 'staff_attendance_id' => $claim['attendance_id'], 'snapshot' => json_encode($claim, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $run;
        }, 3);
    }
}
