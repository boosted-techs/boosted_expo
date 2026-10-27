<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinancePayrollRun;
use App\Models\PayrollStatutoryPolicy;
use App\Models\StaffPerson;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancePayrollStatutory
{
    public const SOURCES = ['https://ura.go.ug/en/changes-to-paye-return-form-following-the-income-tax-amendment-act-2026/', 'https://ura.go.ug/en/employment-income/', 'https://www.nssfug.org/about-us/membership/'];

    public function template(string $version): array
    {
        abort_unless(in_array($version, ['ug_2026_07', 'ug_before_2026_07'], true), 422, 'Choose a known statutory template or provide reviewed custom rules.');
        $resident = $version === 'ug_2026_07' ? [['up_to' => '335000', 'percent' => '0'], ['up_to' => '410000', 'percent' => '20'], ['up_to' => '485000', 'percent' => '25'], ['up_to' => '10000000', 'percent' => '30'], ['up_to' => null, 'percent' => '40']] : [['up_to' => '235000', 'percent' => '0'], ['up_to' => '335000', 'percent' => '10'], ['up_to' => '410000', 'percent' => '20'], ['up_to' => '10000000', 'percent' => '30'], ['up_to' => null, 'percent' => '40']];

        return ['resident' => $resident, 'nonresident' => [['up_to' => '335000', 'percent' => '10'], ['up_to' => '410000', 'percent' => '20'], ['up_to' => '10000000', 'percent' => '30'], ['up_to' => null, 'percent' => '40']], 'nssf_employee_percent' => '5', 'nssf_employer_percent' => '10', 'rounding' => 'whole_half_up', 'source_urls' => self::SOURCES, 'version' => $version];
    }

    public function validateRules(array $rules): void
    {
        foreach (['resident', 'nonresident'] as $key) {
            $bands = $rules[$key] ?? [];
            abort_unless(is_array($bands) && count($bands) > 0 && count($bands) <= 12, 422, 'Provide a bounded progressive tax schedule.');
            $lower = BigDecimal::zero();
            foreach ($bands as $i => $band) {
                abort_unless(isset($band['percent']) && is_numeric($band['percent']) && BigDecimal::of((string) $band['percent'])->isGreaterThanOrEqualTo(0) && BigDecimal::of((string) $band['percent'])->isLessThanOrEqualTo(100), 422, 'Tax percentages must be between zero and one hundred.');
                if (($band['up_to'] ?? null) === null) {
                    abort_unless($i === array_key_last($bands), 422, 'Only the last tax band can be unbounded.');
                } else {
                    abort_unless(is_numeric($band['up_to']) && BigDecimal::of((string) $band['up_to'])->isGreaterThan($lower), 422, 'Tax thresholds must increase.');
                    $lower = BigDecimal::of((string) $band['up_to']);
                }
            }abort_unless(($bands[array_key_last($bands)]['up_to'] ?? null) === null, 422, 'The final tax band must cover the remaining income.');
        }
        foreach (['nssf_employee_percent', 'nssf_employer_percent'] as $key) {
            abort_unless(isset($rules[$key]) && is_numeric($rules[$key]) && BigDecimal::of((string) $rules[$key])->isGreaterThanOrEqualTo(0) && BigDecimal::of((string) $rules[$key])->isLessThanOrEqualTo(100), 422, 'Configure valid contribution percentages.');
        }abort_unless(in_array($rules['rounding'] ?? '', ['whole_half_up', 'whole_floor', 'two_decimals'], true), 422, 'Choose the verified return rounding policy.');
    }

    public function propose(Business $business, array $data, int $actor): PayrollStatutoryPolicy
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            abort_unless($business->country_code === 'UG' && $business->base_currency === 'UGX', 422, 'Uganda statutory templates require a UG business reporting in UGX.');
            $rules = $data['template'] === 'custom' ? $data['rules'] : $this->template($data['template']);
            $this->validateRules($rules);
            if ($data['template'] === 'ug_2026_07') {
                abort_unless($data['starts_on'] >= '2026-07-01', 422, 'The revised resident template starts on or after 1 July 2026.');
            }if ($data['template'] === 'ug_before_2026_07') {
                abort_unless($data['ends_on'] <= '2026-06-30', 422, 'The earlier resident template ends by 30 June 2026.');
            }

            return PayrollStatutoryPolicy::create(['business_id' => $business->id, 'name' => $data['name'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'rules' => $rules, 'source_note' => $data['source_note'], 'status' => 'proposed', 'created_by' => $actor]);
        }, 3);
    }

    public function approve(PayrollStatutoryPolicy $policy, int $actor): void
    {
        DB::transaction(function () use ($policy, $actor) {
            app(OrderOperations::class)->lockForWork($policy->business_id, 'finance');
            $policy = PayrollStatutoryPolicy::query()->where('business_id', $policy->business_id)->lockForUpdate()->findOrFail($policy->id);
            abort_unless($policy->status === 'proposed' && $policy->created_by !== $actor, 422, 'A different reviewer must approve this proposed statutory policy.');
            $this->validateRules($policy->rules);
            abort_if(PayrollStatutoryPolicy::query()->where('business_id', $policy->business_id)->where('status', 'approved')->whereDate('starts_on', '<=', $policy->ends_on)->whereDate('ends_on', '>=', $policy->starts_on)->exists(), 422, 'Approved statutory policy periods cannot overlap.');
            $policy->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now()]);
            app(PlatformAccess::class)->audit($policy->business_id, $actor, 'payroll.statutory_policy_approved', $policy->source_note, [], ['policy_id' => $policy->id, 'rules' => $policy->rules]);
        }, 3);
    }

    public function classify(Business $business, StaffPerson $person, array $data, int $actor): void
    {
        DB::transaction(function () use ($business, $person, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $person = StaffPerson::query()->where('business_id', $business->id)->findOrFail($person->id);
            $before = $person->only(['payroll_tax_class', 'nssf_applicable', 'payroll_classification_note']);
            $person->update(['payroll_tax_class' => $data['payroll_tax_class'], 'nssf_applicable' => $data['nssf_applicable'], 'payroll_classification_note' => $data['reason'], 'payroll_classified_by' => $actor, 'payroll_classified_at' => now()]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'payroll.person_classified', $data['reason'], $before, $person->only(['payroll_tax_class', 'nssf_applicable', 'payroll_classification_note']));
        }, 3);
    }

    private function round(BigDecimal $amount, array $rules): BigDecimal
    {
        return $amount->toScale($rules['rounding'] === 'two_decimals' ? 2 : 0, $rules['rounding'] === 'whole_floor' ? RoundingMode::Down : RoundingMode::HalfUp)->toScale(2);
    }

    public function tax(BigDecimal $income, array $bands): BigDecimal
    {
        $tax = BigDecimal::zero();
        $lower = BigDecimal::zero();
        foreach ($bands as $band) {
            $upper = ($band['up_to'] ?? null) !== null ? BigDecimal::of((string) $band['up_to']) : $income;
            $slice = BigDecimal::min($income, $upper)->minus($lower);
            if ($slice->isGreaterThan(0)) {
                $tax = $tax->plus($slice->multipliedBy((string) $band['percent'])->dividedBy(100, 12, RoundingMode::HalfUp));
            }if ($income->isLessThanOrEqualTo($upper)) {
                break;
            }$lower = $upper;
        }

        return $tax;
    }

    public function prior(Business $business, StaffPerson $person, string $from, ?int $exceptRun = null): array
    {
        $sum = ['taxable' => BigDecimal::zero(), 'nssf_base' => BigDecimal::zero(), 'paye' => BigDecimal::zero(), 'nssf_employee' => BigDecimal::zero(), 'nssf_employer' => BigDecimal::zero()];
        $proof = [];
        foreach (FinancePayrollRun::query()->where('business_id', $business->id)->whereDate('period_from', '>=', substr($from, 0, 7).'-01')->whereDate('period_to', '<', $from)->whereNotIn('posting_status', ['reversed', 'withdrawn'])->when($exceptRun, fn ($q) => $q->whereKeyNot($exceptRun))->with('lines')->orderBy('period_to')->orderBy('id')->get() as $run) {
            $line = $run->lines->firstWhere('staff_person_id', $person->id);
            if (! $line) {
                continue;
            }abort_unless($run->posting_status === 'posted' && isset($line->source_snapshot['statutory']), 422, 'Finish earlier payrolls and use verified statutory month-to-date sources before calculating another payroll in this month.');
            $s = $line->source_snapshot['statutory'];
            abort_unless($s['tax_class'] === $person->payroll_tax_class, 422, 'The employee tax classification changed within this payroll month. Resolve its prior statutory basis first.');
            foreach ($sum as $key => $value) {
                $sum[$key] = $value->plus((string) $s[$key]);
            }$proof[] = ['run_id' => $run->id, 'sha256' => $run->sha256, 'line_id' => $line->id, 'statutory' => $s];
        }

        return [...$sum, 'fingerprint' => app(FinanceWorkflows::class)->hash($proof), 'sources' => $proof];
    }

    public function calculate(Business $business, StaffPerson $person, PayrollStatutoryPolicy $policy, string $from, BigDecimal $taxable, BigDecimal $nssfBase): array
    {
        abort_unless(in_array($person->payroll_tax_class, ['resident', 'nonresident', 'secondary_30', 'secondary_40', 'exempt'], true) && $person->nssf_applicable !== null && $person->payroll_classified_by, 422, 'Record and verify this employee’s tax residency, NSSF eligibility and evidence before automatic payroll.');
        $prior = $this->prior($business, $person, $from);
        $rules = $policy->rules;
        $this->validateRules($rules);
        $income = $prior['taxable']->plus($taxable);
        $class = $person->payroll_tax_class;
        $tax = match ($class) {
            'exempt' => BigDecimal::zero(),'secondary_30' => $income->multipliedBy('0.30'),'secondary_40' => $income->multipliedBy('0.40'),default => $this->tax($income, $rules[$class])
        };
        $paye = $this->round($tax, $rules)->minus($prior['paye']);
        $monthBase = $prior['nssf_base']->plus($person->nssf_applicable ? $nssfBase : 0);
        $employee = $person->nssf_applicable ? $this->round($monthBase->multipliedBy((string) $rules['nssf_employee_percent'])->dividedBy(100, 12, RoundingMode::HalfUp), $rules)->minus($prior['nssf_employee']) : BigDecimal::zero();
        $employer = $person->nssf_applicable ? $this->round($monthBase->multipliedBy((string) $rules['nssf_employer_percent'])->dividedBy(100, 12, RoundingMode::HalfUp), $rules)->minus($prior['nssf_employer']) : BigDecimal::zero();
        abort_unless(! $paye->isLessThan(0) && ! $employee->isLessThan(0) && ! $employer->isLessThan(0), 422, 'A prior statutory overpayment requires an evidenced payroll adjustment; it cannot be silently erased.');

        return ['policy_id' => $policy->id, 'policy_name' => $policy->name, 'rules' => $rules, 'source_note' => $policy->source_note, 'tax_class' => $class, 'classification_evidence' => $person->payroll_classification_note, 'nssf_applicable' => $person->nssf_applicable, 'taxable' => (string) $taxable->toScale(2), 'nssf_base' => (string) ($person->nssf_applicable ? $nssfBase : BigDecimal::zero())->toScale(2), 'paye' => (string) $paye->toScale(2), 'nssf_employee' => (string) $employee->toScale(2), 'nssf_employer' => (string) $employer->toScale(2), 'prior_fingerprint' => $prior['fingerprint'], 'prior_sources' => $prior['sources'], 'remit_by' => Carbon::parse($from)->addMonthNoOverflow()->day(15)->toDateString()];
    }
}
