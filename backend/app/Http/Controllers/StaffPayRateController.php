<?php

namespace App\Http\Controllers;

use App\Models\StaffPayRate;
use App\Services\FinanceTimePayroll;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffPayRateController extends Controller
{
    private function check(Request $r, string $ability): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $ability), 403);
    }

    public function propose(Request $r, FinanceTimePayroll $payroll): RedirectResponse
    {
        $this->check($r, 'finance.payroll.prepare');
        $data = $r->validate(['staff_person_id' => ['required', 'integer', 'min:1'], 'basis' => ['required', 'in:hourly,daily'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'agreement_reference' => ['required', 'string', 'min:10', 'max:250']]);
        $payroll->proposeRate($r->attributes->get('activeBusiness'), $data, $r->user()->id);

        return back()->with('status', 'Pay agreement proposed for independent approval.');
    }

    public function approve(Request $r, int $rate, FinanceTimePayroll $payroll): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $record = StaffPayRate::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($rate);
        $payroll->approveRate($record, $r->user()->id);

        return back()->with('status', 'Pay agreement approved and locked.');
    }

    public function prepare(Request $r, FinanceTimePayroll $payroll): RedirectResponse
    {
        $this->check($r, 'finance.payroll.prepare');
        $data = $r->validate(['period_from' => ['required', 'date_format:Y-m-d'], 'period_to' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($r->attributes->get('activeBusiness')->timezone)->toDateString(), 'after_or_equal:period_from'], 'staff_person_ids' => ['required', 'array', 'min:1', 'max:200'], 'staff_person_ids.*' => ['required', 'integer', 'distinct', 'min:1'], 'deductions' => ['nullable', 'array', 'max:200'], 'deductions.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'notes' => ['required', 'string', 'min:15', 'max:2000']]);
        $run = $payroll->prepare($r->attributes->get('activeBusiness'), $data, $r->user()->id);

        return redirect()->route('finance.payroll.show', $run->id)->with('status', 'Payroll prepared from approved time and agreed rates. Review and post before settling wages.');
    }
}
