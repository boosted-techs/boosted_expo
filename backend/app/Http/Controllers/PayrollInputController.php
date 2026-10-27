<?php

namespace App\Http\Controllers;

use App\Models\FinancePayrollRun;
use App\Models\FinancePostingRequest;
use App\Models\PayrollStatutoryPolicy;
use App\Models\StaffPerson;
use App\Services\FinancePayrollInputs;
use App\Services\FinancePayrollStatutory;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PayrollInputController extends Controller
{
    private function check(Request $r, string $permission = 'finance.payroll.prepare'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function policy(Request $r, FinancePayrollStatutory $statutory): RedirectResponse
    {
        $this->check($r);
        $data = $r->validate(['name' => ['required', 'string', 'min:3', 'max:150'], 'template' => ['required', 'in:ug_2026_07,ug_before_2026_07,custom'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'rules' => ['required_if:template,custom', 'nullable', 'array'], 'rules.resident' => ['nullable', 'array', 'max:12'], 'rules.resident.*' => ['array:up_to,percent'], 'rules.nonresident' => ['nullable', 'array', 'max:12'], 'rules.nonresident.*' => ['array:up_to,percent'], 'rules.resident.*.up_to' => ['nullable', 'numeric', 'min:0', 'max:999999999999'], 'rules.resident.*.percent' => ['nullable', 'numeric', 'between:0,100'], 'rules.nonresident.*.up_to' => ['nullable', 'numeric', 'min:0', 'max:999999999999'], 'rules.nonresident.*.percent' => ['nullable', 'numeric', 'between:0,100'], 'rules.nssf_employee_percent' => ['nullable', 'numeric', 'between:0,100'], 'rules.nssf_employer_percent' => ['nullable', 'numeric', 'between:0,100'], 'rules.rounding' => ['nullable', 'in:whole_half_up,whole_floor,two_decimals'], 'source_note' => ['required', 'string', 'min:20', 'max:5000']]);
        if ($data['template'] === 'custom') {
            $custom = $data['rules'];
            $data['rules'] = ['resident' => array_values(array_filter($custom['resident'] ?? [], fn ($b) => isset($b['percent']) && $b['percent'] !== '')), 'nonresident' => array_values(array_filter($custom['nonresident'] ?? [], fn ($b) => isset($b['percent']) && $b['percent'] !== '')), 'nssf_employee_percent' => $custom['nssf_employee_percent'] ?? null, 'nssf_employer_percent' => $custom['nssf_employer_percent'] ?? null, 'rounding' => $custom['rounding'] ?? null];
        }
        $statutory->propose($r->attributes->get('activeBusiness'), $data, $r->user()->id);

        return back()->with('status', 'Dated statutory rules proposed. A separate reviewer must verify and approve them before automatic payroll.');
    }

    public function approvePolicy(Request $r, int $policy, FinancePayrollStatutory $statutory): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $row = PayrollStatutoryPolicy::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($policy);
        $statutory->approve($row, $r->user()->id);

        return back()->with('status', 'Statutory policy approved for its dates. Previously prepared payroll keeps its frozen rules.');
    }

    public function classify(Request $r, int $person, FinancePayrollStatutory $statutory): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $business = $r->attributes->get('activeBusiness');
        $row = StaffPerson::query()->where('business_id', $business->id)->findOrFail($person);
        $data = $r->validate(['payroll_tax_class' => ['required', 'in:resident,nonresident,secondary_30,secondary_40,exempt'], 'nssf_applicable' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $statutory->classify($business, $row, $data, $r->user()->id);

        return back()->with('status', 'Payroll residency, employment-tax treatment and NSSF eligibility recorded with evidence.');
    }

    public function output(Request $r, FinancePayrollInputs $inputs): RedirectResponse
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $data = $r->validate(['staff_pay_rate_id' => ['required', 'integer', 'min:1'], 'worked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($business->timezone)->toDateString()], 'packing_job_id' => ['nullable', 'integer', 'min:1'], 'sales_order_id' => ['nullable', 'integer', 'min:1'], 'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'], 'unit' => ['required', 'string', 'max:30'], 'work_code' => ['required', 'string', 'max:50'], 'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'evidence' => ['required', 'string', 'min:20', 'max:3000']]);
        $inputs->output($business, $data, $r->user()->id);

        return back()->with('status', 'Verified output proposed for independent quantity and earnings review.');
    }

    public function adjustment(Request $r, FinancePayrollInputs $inputs): RedirectResponse
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $data = $r->validate(['staff_person_id' => ['required', 'integer', 'min:1'], 'effective_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($business->timezone)->toDateString()], 'kind' => ['required', 'in:earning,deduction,benefit'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'taxable' => ['required', 'boolean'], 'nssf_eligible' => ['required', 'boolean'], 'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'evidence' => ['required', 'string', 'min:20', 'max:3000']]);
        $inputs->adjustment($business, $data, $r->user()->id);

        return back()->with('status', 'Pay adjustment proposed. It needs a separate review and may be claimed by one payroll only.');
    }

    public function review(Request $r, string $type, int $source, string $decision, FinancePayrollInputs $inputs): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $data = $r->validate(['note' => ['required', 'string', 'min:20', 'max:3000']]);
        $inputs->review($r->attributes->get('activeBusiness'), $type, $source, $decision === 'approve', $data['note'], $r->user()->id);

        return back()->with('status', 'Pay input reviewed with the decision retained.');
    }

    public function absence(Request $r, string $type, int $source, FinancePayrollInputs $inputs): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $data = $r->validate(['pay_treatment' => ['required', 'in:paid,unpaid'], 'unpaid_day_units' => ['required_if:pay_treatment,unpaid', 'nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:3660'], 'reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $inputs->classifyAbsence($r->attributes->get('activeBusiness'), $type, $source, $data, $r->user()->id);

        return back()->with('status', 'Paid/unpaid salary-day treatment approved with its basis and reviewer retained.');
    }

    public function prepare(Request $r, FinancePayrollInputs $inputs): RedirectResponse
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $data = $r->validate(['period_from' => ['required', 'date_format:Y-m-d'], 'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:'.now($business->timezone)->toDateString()], 'statutory_policy_id' => ['required', 'integer', 'min:1'], 'staff_person_ids' => ['required', 'array', 'min:1', 'max:200'], 'staff_person_ids.*' => ['required', 'integer', 'distinct', 'min:1'], 'notes' => ['required', 'string', 'min:20', 'max:3000']]);
        $run = $inputs->prepare($business, $data, $r->user()->id);

        return redirect()->route('finance.payroll.show', $run->id)->with('status', 'Payroll prepared from effective agreements, approved work/adjustments and a frozen statutory policy. Independent posting approval is still required.');
    }

    public function withdraw(Request $r, int $run, FinancePayrollInputs $inputs): RedirectResponse
    {
        $business = $r->attributes->get('activeBusiness');
        $row = FinancePayrollRun::query()->where('business_id', $business->id)->findOrFail($run);
        $review = FinancePostingRequest::query()->where('business_id', $business->id)->where('source_type', 'payroll_run')->where('source_id', $row->id)->first();
        $this->check($r, $review?->status === 'approved' ? 'finance.reviews.approve' : 'finance.payroll.prepare');
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $inputs->withdrawRun($row, $r->user()->id, $data['reason']);

        return back()->with('status', 'Unposted payroll withdrawn. Its source claims/history remain; corrected work may be prepared as a new revision.');
    }

    public function withdrawInput(Request $r, string $type, int $source, FinancePayrollInputs $inputs): RedirectResponse
    {
        $this->check($r);
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $canReview = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.reviews.approve');
        $inputs->withdrawInput($r->attributes->get('activeBusiness'), $type, $source, $data['reason'], $r->user()->id, $canReview);

        return back()->with('status', 'Unclaimed pay input withdrawn with its original evidence retained.');
    }
}
