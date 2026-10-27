<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\FinancePayrollLine;
use App\Models\FinancePayrollRun;
use App\Models\StaffPerson;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancePayroll
{
    public function prepare(Business $business, array $data, int $actor): FinancePayrollRun
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $from = $data['period_from'];
            $to = $data['period_to'];
            if ($from > $to || $to > now($business->timezone)->toDateString() ||
                substr($from, 0, 7) !== substr($to, 0, 7)) {
                throw ValidationException::withMessages(['period_to' => 'Payroll must cover dates within one calendar month ending no later than today.']);
            }
            $overlapping = FinancePayrollRun::query()->where('business_id', $business->id)
                ->whereDate('period_from', '<=', $to)->whereDate('period_to', '>=', $from)
                ->lockForUpdate()->get();
            $revision = 1;
            foreach ($overlapping as $prior) {
                if ($prior->period_from->toDateString() !== $from
                    || $prior->period_to->toDateString() !== $to
                    || $prior->posting_status !== 'reversed') {
                    throw ValidationException::withMessages(['period_from' => 'A payroll already covers these dates. Only a formally reversed payroll can be replaced for the exact same period.']);
                }
                $revision = max($revision, (int) $prior->revision + 1);
            }
            app(FinancePeriodControls::class)->assertWritable($business, $to);
            $members = BusinessMembership::query()->where('business_id', $business->id)
                ->where('active', true)->get()->keyBy('user_id');
            $math = app(FinanceMath::class);
            $gross = BigDecimal::zero();
            $deductions = BigDecimal::zero();
            $net = BigDecimal::zero();
            $lines = [];
            $seen = [];
            foreach ($data['lines'] as $entry) {
                $person = null;
                if (isset($entry['staff_person_id'])) {
                    $person = StaffPerson::query()->where('business_id', $business->id)->findOrFail($entry['staff_person_id']);
                }
                $userId = $person ? $person->user_id : (int) $entry['user_id'];
                $identity = $userId ? 'user:'.$userId : 'person:'.$person->id;
                abort_unless(! isset($seen[$identity]) && ($person || $members->has($userId)), 422, 'Include each active staff person once in the payroll.');
                $seen[$identity] = true;
                $g = $math->money((string) $entry['gross']);
                $d = $math->money((string) ($entry['deductions'] ?? '0'));
                if ($g->isLessThanOrEqualTo(0) || $d->isLessThan(0) || $d->isGreaterThan($g)) {
                    throw ValidationException::withMessages(['lines' => 'Gross earnings must be positive; deductions cannot be negative or exceed earnings.']);
                }
                $n = $g->minus($d);
                $gross = $gross->plus($g);
                $deductions = $deductions->plus($d);
                $net = $net->plus($n);
                $details = $person ? ['staff_person_id' => $person->id, 'name_snapshot' => $person->name, 'source_snapshot' => $entry['source_snapshot'] ?? null] : [];
                $lines[] = [...$details, 'user_id' => $userId, 'gross' => (string) $g,
                    'deductions' => (string) $d, 'net' => (string) $n];
            }
            if (count($lines) === 0) {
                throw ValidationException::withMessages(['lines' => 'At least one staff member must be included.']);
            }
            usort($lines, fn ($a, $b) => [$a['user_id'] ?? 0, $a['staff_person_id'] ?? 0] <=> [$b['user_id'] ?? 0, $b['staff_person_id'] ?? 0]);
            $snapshot = ['period_from' => $from, 'period_to' => $to, 'revision' => $revision, 'currency' => $business->base_currency,
                'method' => isset($lines[0]['source_snapshot']) ? 'approved_time_and_agreed_rate' : 'staff-entered-gross-and-manual-deductions',
                'note' => isset($lines[0]['source_snapshot']) ? 'Approved hourly/daily time inputs are frozen; PAYE, NSSF and other statutory deductions remain manually verified.' : 'Gross and deductions are manually verified; attendance and statutory calculations are not automatic.',
                'lines' => $lines, 'gross' => (string) $gross,
                'deductions' => (string) $deductions, 'net' => (string) $net];
            $run = FinancePayrollRun::create([
                'business_id' => $business->id, 'reference' => 'PAYROLL-'.Str::upper((string) Str::ulid()),
                'revision' => $revision, 'period_from' => $from, 'period_to' => $to,
                'currency' => $business->base_currency, 'gross_total' => (string) $gross,
                'deduction_total' => (string) $deductions, 'net_total' => (string) $net,
                'posting_status' => 'not_posted', 'notes' => $data['notes'],
                'data_snapshot' => $snapshot,
                'sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'created_by' => $actor,
            ]);
            foreach ($lines as $line) {
                FinancePayrollLine::create([
                    ...$line, 'business_id' => $business->id, 'payroll_run_id' => $run->id,
                ]);
            }

            return $run;
        }, 3);
    }

    public function lines(FinancePayrollRun $run): array
    {
        $data = $run->data_snapshot;
        abort_unless(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)) === $run->sha256
            && $run->currency === $data['currency']
            && $run->period_from->toDateString() === $data['period_from']
            && $run->period_to->toDateString() === $data['period_to']
            && (int) $run->revision === (int) ($data['revision'] ?? 1), 422,
            'The payroll snapshot has changed since its preparation.');
        $gross = BigDecimal::zero();
        $deductions = BigDecimal::zero();
        $net = BigDecimal::zero();
        $rows = $run->lines()->orderBy('id')->get();
        abort_unless($rows->count() === count($data['lines']), 422, 'Payroll staff rows have changed.');
        foreach ($data['lines'] as $i => $line) {
            $row = $rows[$i];
            if (isset($line['staff_person_id'])) {
                abort_unless($row->staff_person_id === $line['staff_person_id'] && $row->name_snapshot === $line['name_snapshot'] && $row->source_snapshot === $line['source_snapshot'], 422, 'A frozen staff identity or payroll source changed.');
            }
            abort_unless($row->business_id === $run->business_id &&
                $row->user_id === $line['user_id'] &&
                BigDecimal::of((string) $row->gross)->isEqualTo($line['gross'])
                && BigDecimal::of((string) $row->deductions)->isEqualTo($line['deductions'])
                && BigDecimal::of((string) $row->net)->isEqualTo($line['net']),
                422, 'A payroll employee amount changed after preparation.');
            $gross = $gross->plus($line['gross']);
            $deductions = $deductions->plus($line['deductions']);
            $net = $net->plus($line['net']);
        }
        abort_unless($gross->isEqualTo((string) $run->gross_total)
            && $deductions->isEqualTo((string) $run->deduction_total)
            && $net->isEqualTo((string) $run->net_total)
            && $gross->minus($deductions)->isEqualTo($net), 422, 'Payroll totals do not balance.');
        $ledger = app(FinanceLedger::class);
        $lines = [$ledger->line('payroll_expense', (string) $gross, '0',
            (string) $gross, '0', 'Accrued employee payroll (not paid)')];
        if ($net->isGreaterThan(0)) {
            $lines[] = $ledger->line(
                'payroll_payable', '0', (string) $net, '0', (string) $net, 'Net wages payable');
        }
        if ($deductions->isGreaterThan(0)) {
            $lines[] = $ledger->line(
                'payroll_withholding', '0', (string) $deductions, '0', (string) $deductions,
                'Manually entered withholding/deductions; not yet remitted');
        }

        return $lines;
    }
}
