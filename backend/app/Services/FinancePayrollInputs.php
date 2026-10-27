<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceApprovalEvent;
use App\Models\FinancePayrollRun;
use App\Models\FinancePostingRequest;
use App\Models\PackingJob;
use App\Models\PayrollStatutoryPolicy;
use App\Models\SalesOrder;
use App\Models\StaffAttendance;
use App\Models\StaffBreak;
use App\Models\StaffLeave;
use App\Models\StaffOutput;
use App\Models\StaffPayAdjustment;
use App\Models\StaffPayRate;
use App\Models\StaffPerson;
use App\Models\StaffShift;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancePayrollInputs
{
    private const MODELS = ['output' => StaffOutput::class, 'adjustment' => StaffPayAdjustment::class, 'attendance' => StaffAttendance::class, 'leave' => StaffLeave::class, 'absence' => StaffShift::class];

    public function output(Business $business, array $data, int $actor): StaffOutput
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $rate = StaffPayRate::query()->where('business_id', $business->id)->effectiveOn($data['worked_on'])->where('basis', 'piece')->findOrFail($data['staff_pay_rate_id']);
            $person = StaffPerson::query()->where('business_id', $business->id)->where('active', true)->findOrFail($rate->staff_person_id);
            abort_unless($rate->output_unit === $data['unit'] && $rate->work_code === $data['work_code'], 422, 'Output units and work type must match the approved piece-rate agreement.');
            if (! empty($data['sales_order_id'])) {
                SalesOrder::query()->where('business_id', $business->id)->findOrFail($data['sales_order_id']);
            }if (! empty($data['packing_job_id'])) {
                $job = PackingJob::query()->where('business_id', $business->id)->with('orderLine')->findOrFail($data['packing_job_id']);
                abort_unless($job->status === 'completed' && $job->product->base_unit === $data['unit'], 422, 'Select a completed job and its actual product unit.');
                abort_unless(empty($data['sales_order_id']) || $job->orderLine?->sales_order_id === (int) $data['sales_order_id'], 422, 'The packing job does not belong to this order.');
                $data['sales_order_id'] = $job->orderLine?->sales_order_id;
                $this->outputCapacity($business, $job, (string) $data['work_code'], (string) $data['quantity']);
            }

            return StaffOutput::create([...$data, 'business_id' => $business->id, 'staff_person_id' => $person->id, 'created_by' => $actor, 'status' => 'proposed']);
        }, 3);
    }

    private function outputCapacity(Business $business, PackingJob $job, string $code, string $quantity, ?int $except = null): void
    {
        $available = BigDecimal::zero();
        foreach ($job->cartons()->where('status', '!=', 'repacked')->with('contents')->get() as $carton) {
            foreach ($carton->contents as $content) {
                $available = $available->plus((string) $content->quantity);
            }
        }$claimed = BigDecimal::of($quantity);
        foreach (StaffOutput::query()->where('business_id', $business->id)->where('packing_job_id', $job->id)->where('work_code', $code)->whereIn('status', ['proposed', 'approved'])->when($except, fn ($q) => $q->whereKeyNot($except))->pluck('quantity') as $value) {
            $claimed = $claimed->plus((string) $value);
        }abort_unless(! $claimed->isGreaterThan($available), 422, 'Recorded staff output exceeds the actual completed job quantity for this work type.');
    }

    public function adjustment(Business $business, array $data, int $actor): StaffPayAdjustment
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            StaffPerson::query()->where('business_id', $business->id)->findOrFail($data['staff_person_id']);
            if ($data['kind'] === 'deduction') {
                $data['taxable'] = false;
                $data['nssf_eligible'] = false;
            }

            return StaffPayAdjustment::create([...$data, 'business_id' => $business->id, 'created_by' => $actor, 'status' => 'proposed']);
        }, 3);
    }

    public function review(Business $business, string $type, int $id, bool $approve, string $note, int $actor): void
    {
        DB::transaction(function () use ($business, $type, $id, $approve, $note, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            abort_unless(in_array($type, ['output', 'adjustment'], true), 404);
            $row = self::MODELS[$type]::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($id);
            $person = StaffPerson::query()->where('business_id', $business->id)->findOrFail($row->staff_person_id);
            abort_unless($row->status === 'proposed' && $row->created_by !== $actor && $person->user_id !== $actor, 422, 'A different authorised person must review these unapproved earnings inputs.');
            if ($approve && $type === 'output') {
                $rate = StaffPayRate::query()->where('business_id', $business->id)->effectiveOn($row->worked_on->toDateString())->findOrFail($row->staff_pay_rate_id);
                abort_unless($rate->staff_person_id === $row->staff_person_id && $rate->basis === 'piece', 422, 'Output no longer matches the effective piece agreement.');
                if ($row->packing_job_id) {
                    $job = PackingJob::query()->where('business_id', $business->id)->findOrFail($row->packing_job_id);
                    $this->outputCapacity($business, $job, $row->work_code, (string) $row->quantity, $row->id);
                }
            }$row->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $approve ? $actor : null, 'approved_at' => $approve ? now() : null, 'review_note' => $note]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'payroll.input_'.$row->status, $note, [], ['type' => $type, 'source_id' => $id]);
        }, 3);
    }

    public function classifyAbsence(Business $business, string $type, int $id, array $data, int $actor): void
    {
        DB::transaction(function () use ($business, $type, $id, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            abort_unless(in_array($type, ['leave', 'absence'], true), 404);
            $row = self::MODELS[$type]::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($id);
            app(FinancePeriodControls::class)->assertWritable($business, $row->starts_at->copy()->timezone($business->timezone)->toDateString());
            $person = StaffPerson::query()->where('business_id', $business->id)->findOrFail($row->staff_person_id);
            abort_unless($person->user_id !== $actor, 422, 'A different person must review your own pay-affecting absence.');
            $days = count($this->dates($row->starts_at, $row->ends_at));
            $units = BigDecimal::of((string) ($data['unpaid_day_units'] ?? '0'));
            abort_unless($data['pay_treatment'] === 'paid' || ($units->isGreaterThan(0) && ! $units->isGreaterThan($days)), 422, 'Enter the agreed unpaid salary-day units within this absence period.');
            $row->update(['pay_treatment' => $data['pay_treatment'], 'unpaid_day_units' => $data['pay_treatment'] === 'unpaid' ? (string) $units : '0', 'pay_reviewed_by' => $actor, 'pay_reviewed_at' => now(), 'pay_review_note' => $data['reason']]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'payroll.absence_classified', $data['reason'], [], ['type' => $type, 'source_id' => $id, 'pay_treatment' => $data['pay_treatment'], 'unpaid_day_units' => $row->unpaid_day_units]);
        }, 3);
    }

    private function dates(Carbon $start, Carbon $end): array
    {
        $date = $start->copy()->timezone('Africa/Kampala')->startOfDay();
        $last = $end->copy()->subMicrosecond()->timezone('Africa/Kampala')->startOfDay();
        $days = [];
        abort_unless($date->diffInDays($last) <= 3660, 422, 'Resolve unusually long absence periods before payroll.');
        while ($date->lte($last)) {
            $days[] = $date->toDateString();
            $date->addDay();
        }

        return $days;
    }

    private function assertUnclaimed(int $businessId, string $type, int $id): void
    {
        abort_if(DB::table('finance_payroll_input_claims as c')->join('finance_payroll_runs as r', 'r.id', '=', 'c.payroll_run_id')->where('c.business_id', $businessId)->where('c.source_type', $type)->where('c.source_id', $id)->whereNotIn('r.posting_status', ['reversed', 'withdrawn'])->exists(), 422, 'This approved work or adjustment already belongs to a payroll.');
        if ($type === 'attendance') {
            abort_if(DB::table('finance_payroll_time_claims as c')->join('finance_payroll_runs as r', 'r.id', '=', 'c.payroll_run_id')->where('c.business_id', $businessId)->where('c.staff_attendance_id', $id)->whereNotIn('r.posting_status', ['reversed', 'withdrawn'])->exists(), 422, 'This attendance already belongs to an earlier time-based payroll.');
        }
    }

    private function source(string $type, $row): array
    {
        return ['type' => $type, 'id' => $row->id, 'data' => $row->toArray()];
    }

    public function prepare(Business $business, array $data, int $actor): FinancePayrollRun
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $from = $data['period_from'];
            $to = $data['period_to'];
            abort_unless($from <= $to && substr($from, 0, 7) === substr($to, 0, 7) && $to <= now($business->timezone)->toDateString(), 422, 'Use a completed payroll period within one calendar month.');
            $policy = PayrollStatutoryPolicy::query()->where('business_id', $business->id)->where('status', 'approved')->whereDate('starts_on', '<=', $from)->whereDate('ends_on', '>=', $to)->findOrFail($data['statutory_policy_id']);
            $monthDays = Carbon::parse($from)->daysInMonth;
            $utcFrom = Carbon::parse($from, 'Africa/Kampala')->startOfDay()->utc();
            $utcTo = Carbon::parse($to, 'Africa/Kampala')->endOfDay()->utc();
            $lines = [];
            $claims = [];
            foreach (array_unique($data['staff_person_ids']) as $id) {
                $person = StaffPerson::query()->where('business_id', $business->id)->findOrFail($id);
                $sources = [];
                $gross = BigDecimal::zero();
                $taxable = BigDecimal::zero();
                $nssfBase = BigDecimal::zero();
                $manual = BigDecimal::zero();
                $dayRates = [];
                $monthly = [];
                $unpaid = [];
                for ($day = Carbon::parse($from); $day->toDateString() <= $to; $day->addDay()) {
                    $date = $day->toDateString();
                    $rates = StaffPayRate::query()->where('business_id', $business->id)->where('staff_person_id', $person->id)->effectiveOn($date)->get();
                    abort_unless($rates->count() <= 1, 422, 'Overlapping pay agreements must be resolved.');
                    if ($rate = $rates->first()) {
                        $dayRates[$date] = $rate;
                        if ($rate->basis === 'monthly') {
                            $monthly[$rate->id] ??= ['rate' => $rate, 'days' => 0, 'unpaid' => BigDecimal::zero()];
                            $monthly[$rate->id]['days']++;
                        }
                    }
                }
                foreach (['leave' => StaffLeave::query()->where('status', 'approved'), 'absence' => StaffShift::query()->where('status', 'absent')] as $type => $query) {
                    foreach ($query->where('business_id', $business->id)->where('staff_person_id', $person->id)->where('starts_at', '<=', $utcTo)->where('ends_at', '>', $utcFrom)->get() as $absence) {
                        $dates = $this->dates($absence->starts_at, $absence->ends_at);
                        $relevant = array_values(array_filter($dates, fn ($d) => isset($dayRates[$d]) && $dayRates[$d]->basis === 'monthly'));
                        if (! $relevant) {
                            continue;
                        }abort_unless($absence->pay_treatment !== 'unverified' && $absence->pay_reviewed_by, 422, 'Classify and review monthly leave/absence as paid or agreed unpaid salary-day units first.');
                        $sources[] = $this->source($type, $absence);
                        if ($absence->pay_treatment === 'unpaid') {
                            $units = BigDecimal::of((string) $absence->unpaid_day_units)->dividedBy(count($dates), 12, RoundingMode::HalfUp);
                            foreach ($relevant as $date) {
                                $unpaid[$date] = ($unpaid[$date] ?? BigDecimal::zero())->plus($units);
                                abort_unless(! $unpaid[$date]->isGreaterThan(1), 422, 'Overlapping unpaid absence records count the same salary day twice.');
                                $monthly[$dayRates[$date]->id]['unpaid'] = $monthly[$dayRates[$date]->id]['unpaid']->plus($units);
                            }
                        }
                    }
                }
                foreach ($monthly as $bucket) {
                    $rate = $bucket['rate'];
                    $base = BigDecimal::of((string) $rate->amount)->multipliedBy($bucket['days'])->dividedBy($monthDays, 12, RoundingMode::HalfUp);
                    $deduction = BigDecimal::of((string) $rate->amount)->multipliedBy($bucket['unpaid'])->dividedBy((string) ($rate->unpaid_day_divisor ?? $monthDays), 12, RoundingMode::HalfUp);
                    $earned = app(FinanceMath::class)->money($base->minus($deduction));
                    abort_unless(! $earned->isLessThan(0), 422, 'Unpaid salary-day deductions exceed the monthly entitlement.');
                    $gross = $gross->plus($earned);
                    $sources[] = ['type' => 'monthly_agreement', 'id' => $rate->id, 'data' => $rate->toArray(), 'calendar_days' => $bucket['days'], 'unpaid_days' => (string) $bucket['unpaid'], 'gross' => (string) $earned];
                }
                $dailySeconds = [];
                $dailyPaid = [];
                foreach (StaffAttendance::query()->where('business_id', $business->id)->where('staff_person_id', $person->id)->whereRaw('COALESCE(effective_in_at,clocked_in_at) >= ?', [$utcFrom->format('Y-m-d H:i:s')])->whereRaw('COALESCE(effective_in_at,clocked_in_at) <= ?', [$utcTo->format('Y-m-d H:i:s')])->orderByRaw('COALESCE(effective_in_at,clocked_in_at)')->orderBy('id')->get() as $attendance) {
                    $start = $attendance->effective_in_at ?? $attendance->clocked_in_at;
                    $end = $attendance->effective_out_at ?? $attendance->clocked_out_at;
                    abort_unless($attendance->approved_at && $end && $end->gt($start) && $end->lte($utcTo), 422, 'Resolve and approve all selected attendance before payroll.');
                    $date = $start->copy()->timezone('Africa/Kampala')->toDateString();
                    $rate = $dayRates[$date] ?? null;
                    abort_unless($rate, 422, 'No approved agreement covers this attendance.');
                    $this->assertUnclaimed($business->id, 'attendance', $attendance->id);
                    $seconds = $end->getTimestamp() - $start->getTimestamp();
                    $breaks = [];
                    foreach (StaffBreak::query()->where('business_id', $business->id)->where('staff_attendance_id', $attendance->id)->orderBy('id')->get() as $break) {
                        abort_unless($break->ends_at, 422, 'Close open breaks before payroll.');
                        $seconds -= max(0, min($end->getTimestamp(), $break->ends_at->getTimestamp()) - max($start->getTimestamp(), $break->starts_at->getTimestamp()));
                        $breaks[] = $break->toArray();
                    }abort_unless($seconds > 0, 422, 'Attendance contains no payable work time after breaks.');
                    $priorSeconds = $dailySeconds[$date] ?? 0;
                    $dailySeconds[$date] = $priorSeconds + $seconds;
                    $regularSeconds = BigDecimal::of((string) ($rate->regular_daily_hours ?? 8))->multipliedBy(3600)->toInt();
                    $beforeOver = max(0, $priorSeconds - $regularSeconds);
                    $afterOver = max(0, $dailySeconds[$date] - $regularSeconds);
                    $overtime = $rate->overtime_multiplier !== null ? $afterOver - $beforeOver : 0;
                    $normal = $seconds - $overtime;
                    $earned = BigDecimal::zero();
                    if ($rate->basis === 'hourly') {
                        $earned = BigDecimal::of((string) $rate->amount)->multipliedBy($normal)->dividedBy(3600, 12, RoundingMode::HalfUp);
                    } elseif ($rate->basis === 'daily' && ! isset($dailyPaid[$date])) {
                        $earned = BigDecimal::of((string) $rate->amount);
                        $dailyPaid[$date] = true;
                    }
                    if ($overtime > 0 && $rate->basis !== 'piece') {
                        $hourly = $rate->basis === 'hourly' ? BigDecimal::of((string) $rate->amount) : ($rate->basis === 'daily' ? BigDecimal::of((string) $rate->amount)->dividedBy((string) $rate->regular_daily_hours, 12, RoundingMode::HalfUp) : BigDecimal::of((string) ($rate->overtime_hourly_amount ?? '0')));
                        abort_unless($hourly->isGreaterThan(0), 422, 'Monthly overtime needs an explicitly agreed hourly equivalent.');
                        $earned = $earned->plus($hourly->multipliedBy((string) $rate->overtime_multiplier)->multipliedBy($overtime)->dividedBy(3600, 12, RoundingMode::HalfUp));
                    }
                    $earned = app(FinanceMath::class)->money($earned);
                    $gross = $gross->plus($earned);
                    $source = $this->source('attendance', $attendance);
                    $source['breaks'] = $breaks;
                    $source['agreement'] = $rate->toArray();
                    $source['payable_seconds'] = $seconds;
                    $source['overtime_seconds'] = $overtime;
                    $source['gross'] = (string) $earned;
                    $sources[] = $source;
                }
                foreach (StaffOutput::query()->where('business_id', $business->id)->where('staff_person_id', $person->id)->whereDate('worked_on', '>=', $from)->whereDate('worked_on', '<=', $to)->where('status', '!=', 'rejected')->where('status', '!=', 'withdrawn')->orderBy('id')->get() as $output) {
                    abort_unless($output->status === 'approved', 422, 'Review every selected piece-work record before payroll.');
                    $this->assertUnclaimed($business->id, 'output', $output->id);
                    $rate = $dayRates[$output->worked_on->toDateString()] ?? null;
                    abort_unless($rate && $rate->id === $output->staff_pay_rate_id && $rate->basis === 'piece', 422, 'Output no longer matches its approved piece agreement.');
                    $earned = app(FinanceMath::class)->amount((string) $output->quantity, (string) $rate->amount);
                    $gross = $gross->plus($earned);
                    $source = $this->source('output', $output);
                    $source['agreement'] = $rate->toArray();
                    $source['gross'] = (string) $earned;
                    $sources[] = $source;
                }
                $taxable = $gross;
                $nssfBase = $gross;
                foreach (StaffPayAdjustment::query()->where('business_id', $business->id)->where('staff_person_id', $person->id)->whereDate('effective_on', '>=', $from)->whereDate('effective_on', '<=', $to)->whereNotIn('status', ['rejected', 'withdrawn'])->orderBy('id')->get() as $adjustment) {
                    abort_unless($adjustment->status === 'approved', 422, 'Review selected pay adjustments before payroll.');
                    $this->assertUnclaimed($business->id, 'adjustment', $adjustment->id);
                    $amount = BigDecimal::of((string) $adjustment->amount);
                    if ($adjustment->kind === 'deduction') {
                        $manual = $manual->plus($amount);
                    } else {
                        if ($adjustment->kind === 'earning') {
                            $gross = $gross->plus($amount);
                        }if ($adjustment->taxable) {
                            $taxable = $taxable->plus($amount);
                        }if ($adjustment->nssf_eligible) {
                            $nssfBase = $nssfBase->plus($amount);
                        }
                    }$sources[] = $this->source('adjustment', $adjustment);
                }
                abort_unless($gross->isGreaterThan(0), 422, 'No positive approved earnings exist for '.$person->name.' in this period.');
                $stat = app(FinancePayrollStatutory::class)->calculate($business, $person, $policy, $from, $taxable, $nssfBase);
                $deductions = $manual->plus($stat['paye'])->plus($stat['nssf_employee']);
                $snapshot = ['method' => 'approved_inputs_and_statutory_policy', 'entries' => $sources, 'manual_deductions' => (string) $manual->toScale(2), 'statutory' => $stat];
                $lines[] = ['staff_person_id' => $person->id, 'gross' => (string) $gross->toScale(2), 'deductions' => (string) $deductions->toScale(2), 'source_snapshot' => $snapshot];
                foreach ($sources as $source) {
                    if (isset(self::MODELS[$source['type']])) {
                        $claims[] = $source;
                    }
                }
            }
            $run = app(FinancePayroll::class)->prepare($business, [...$data, 'lines' => $lines], $actor);
            foreach ($claims as $claim) {
                DB::table('finance_payroll_input_claims')->insert(['business_id' => $business->id, 'payroll_run_id' => $run->id, 'source_type' => $claim['type'], 'source_id' => $claim['id'], 'data_snapshot' => json_encode($claim, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $run;
        }, 3);
    }

    public function validate(FinancePayrollRun $run): void
    {
        $business = Business::findOrFail($run->business_id);
        foreach ($run->data_snapshot['lines'] as $line) {
            $s = $line['source_snapshot'] ?? [];
            if (($s['method'] ?? '') !== 'approved_inputs_and_statutory_policy') {
                continue;
            }$person = StaffPerson::query()->where('business_id', $run->business_id)->findOrFail($line['staff_person_id']);
            $frozen = $s['statutory'];
            abort_unless($person->payroll_tax_class === $frozen['tax_class'] && $person->nssf_applicable === $frozen['nssf_applicable'] && $person->payroll_classification_note === $frozen['classification_evidence'], 422, 'The verified payroll classification changed. Withdraw and prepare the payroll again.');
            $prior = app(FinancePayrollStatutory::class)->prior($business, $person, $run->period_from->toDateString(), $run->id);
            abort_unless($prior['fingerprint'] === $frozen['prior_fingerprint'], 422, 'Earlier payroll/statutory balances changed. Withdraw and prepare this payroll again.');
            foreach ($s['entries'] as $entry) {
                if (isset(self::MODELS[$entry['type']])) {
                    $current = self::MODELS[$entry['type']]::query()->where('business_id', $run->business_id)->findOrFail($entry['id']);
                    abort_unless($current->toArray() === $entry['data'], 422, 'An approved pay input changed after preparation.');
                    if ($entry['type'] === 'attendance') {
                        $breaks = StaffBreak::query()->where('business_id', $run->business_id)->where('staff_attendance_id', $current->id)->orderBy('id')->get()->toArray();
                        abort_unless($breaks === $entry['breaks'], 422, 'Attendance breaks changed after payroll preparation.');
                    }
                }
            }
        }
    }

    public function withdrawRun(FinancePayrollRun $run, int $actor, string $reason): void
    {
        DB::transaction(function () use ($run, $actor, $reason) {
            app(OrderOperations::class)->lockForWork($run->business_id, 'finance');
            $run = FinancePayrollRun::query()->where('business_id', $run->business_id)->lockForUpdate()->findOrFail($run->id);
            abort_unless($run->posting_status === 'not_posted', 422, 'Only an unposted payroll can be withdrawn.');
            $review = FinancePostingRequest::query()->where('business_id', $run->business_id)->where('source_type', 'payroll_run')->where('source_id', $run->id)->first();
            abort_unless($review?->status === 'approved' ? $review->approved_by === $actor : ($run->created_by === $actor || $review?->requested_by === $actor), 422, 'Only the preparer/requester may withdraw an unapproved run; its approver must withdraw an approval.');
            if ($review) {
                $review->update(['status' => 'rejected']);
                FinanceApprovalEvent::create(['business_id' => $run->business_id, 'posting_request_id' => $review->id, 'actor_id' => $actor, 'action' => 'withdrawn', 'reason' => $reason, 'created_at' => now()]);
            }$run->update(['posting_status' => 'withdrawn']);
            app(PlatformAccess::class)->audit($run->business_id, $actor, 'payroll.unposted_withdrawn', $reason, [], ['payroll_run_id' => $run->id]);
        }, 3);
    }

    public function withdrawInput(Business $business, string $type, int $id, string $reason, int $actor, bool $canReview): void
    {
        DB::transaction(function () use ($business, $type, $id, $reason, $actor, $canReview) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            abort_unless(in_array($type, ['output', 'adjustment'], true), 404);
            $row = self::MODELS[$type]::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($id);
            abort_unless($row->status === 'proposed' ? $row->created_by === $actor : ($row->status === 'approved' && $canReview), 422, 'Only the preparer may withdraw proposed work; an authorised reviewer must withdraw approved work.');
            $this->assertUnclaimed($business->id, $type, $id);
            $row->update(['status' => 'withdrawn']);
            app(PlatformAccess::class)->audit($business->id, $actor, 'payroll.input_withdrawn', $reason, [], ['type' => $type, 'source_id' => $id]);
        }, 3);
    }
}
