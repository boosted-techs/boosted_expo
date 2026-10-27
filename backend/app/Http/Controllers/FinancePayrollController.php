<?php

namespace App\Http\Controllers;

use App\Models\BusinessMembership;
use App\Models\FinanceBankProfile;
use App\Models\FinancePayrollCorrectionEvent;
use App\Models\FinancePayrollReversal;
use App\Models\FinancePayrollRun;
use App\Models\FinancePayrollSettlement;
use App\Models\FinancePostingRequest;
use App\Models\StaffPayRate;
use App\Models\StaffPerson;
use App\Services\FinancePayroll;
use App\Services\FinancePayrollCorrections;
use App\Services\FinancePayrollSettlements;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancePayrollController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'finance.payroll.view');
        $business = $request->attributes->get('activeBusiness');
        $members = BusinessMembership::query()->where('business_id', $business->id)
            ->where('active', true)->with('user')->orderBy('id')->get();
        $runs = FinancePayrollRun::query()->where('business_id', $business->id)
            ->orderByDesc('period_to')->paginate(24);
        $canPrepare = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.payroll.prepare');
        $request->validate(['person_search' => ['nullable', 'string', 'max:120']]);
        $people = StaffPerson::query()->where('business_id', $business->id)->when($request->filled('person_search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('person_search').'%'))->orderBy('name')->paginate(50, ['*'], 'people_page')->withQueryString();
        $rates = StaffPayRate::query()->where('business_id', $business->id)->with('person')->latest('id')->paginate(25, ['*'], 'rates_page')->withQueryString();
        $canApprove = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'finance.reviews.approve');

        return view('finance.payroll', compact('business', 'members', 'runs', 'canPrepare', 'people', 'rates', 'canApprove'));
    }

    public function prepare(Request $request, FinancePayroll $service): RedirectResponse
    {
        $this->check($request, 'finance.payroll.prepare');
        $data = $request->validate([
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['required', 'string', 'min:15', 'max:2000'],
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*.gross' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'],
            'entries.*.deductions' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'],
        ]);
        $lines = [];
        foreach ($data['entries'] as $userId => $entry) {
            if (! filled($entry['gross'] ?? null)) {
                continue;
            }
            if (! ctype_digit((string) $userId)) {
                abort(422, 'Invalid payroll employee.');
            }
            $lines[] = ['user_id' => (int) $userId, 'gross' => $entry['gross'],
                'deductions' => $entry['deductions'] ?? '0'];
        }
        if ($lines === []) {
            return back()->withErrors(['entries' => 'Enter earnings for at least one team member.']);
        }
        $service->prepare($request->attributes->get('activeBusiness'), [
            ...$data, 'lines' => $lines,
        ], $request->user()->id);

        return redirect()->route('finance.payroll.index')->with('status',
            'Payroll prepared as an accrual only. Finance must independently approve posting. No wages or statutory deductions have been paid.');
    }

    public function show(Request $request, int $run, FinancePayrollSettlements $settlementService): View
    {
        $this->check($request, 'finance.payroll.view');
        $business = $request->attributes->get('activeBusiness');
        $run = FinancePayrollRun::query()->where('business_id', $business->id)
            ->with('lines.user', 'lines.person')->findOrFail($run);
        $access = app(BusinessPermissions::class);
        $membership = $request->attributes->get('membership');
        $canSettle = $access->allows($membership, 'finance.payroll.settle');
        $canCorrect = $access->allows($membership, 'finance.payroll.correct');
        $settlements = $canSettle ? FinancePayrollSettlement::query()
            ->where('business_id', $business->id)->where('payroll_run_id', $run->id)
            ->orderByDesc('id')->get() : collect();
        $banks = $canSettle ? FinanceBankProfile::query()->where('business_id', $business->id)
            ->where('active', true)->orderBy('label')->get() : collect();
        $remaining = [];
        foreach ($run->lines as $line) {
            $remaining[$line->id] = (string) $settlementService->remaining($run, 'salary', $line->id)->toScale(2);
        }
        $withholdingRemaining = (string) $settlementService->remaining($run, 'withholding')->toScale(2);
        $reversal = FinancePayrollReversal::query()->where('business_id', $business->id)
            ->where('payroll_run_id', $run->id)->first();
        $correctionReview = $reversal ? FinancePostingRequest::query()
            ->where('business_id', $business->id)->where('source_type', 'payroll_reversal')
            ->where('source_id', $reversal->id)->first() : null;
        $canWithdraw = $canCorrect && $reversal
            && $reversal->posting_status === 'not_posted'
            && (! $correctionReview || $correctionReview->status === 'rejected');
        $correctionEvents = $canCorrect && $reversal ? FinancePayrollCorrectionEvent::query()
            ->where('business_id', $business->id)->where('payroll_reversal_id', $reversal->id)
            ->orderBy('id')->get() : collect();

        return view('finance.payroll-detail', compact('business', 'run', 'canSettle', 'canCorrect',
            'settlements', 'banks', 'remaining', 'withholdingRemaining', 'reversal', 'canWithdraw',
            'correctionEvents'));
    }

    public function payslip(Request $request, int $run, int $line): View
    {
        $business = $request->attributes->get('activeBusiness');
        $record = FinancePayrollRun::query()->where('business_id', $business->id)->findOrFail($run);
        $line = $record->lines()->where('business_id', $business->id)->findOrFail($line);
        $access = app(BusinessPermissions::class);
        $member = $request->attributes->get('membership');
        abort_unless($access->allows($member, 'finance.payroll.view') || ($line->user_id === $request->user()->id && $access->allows($member, 'staff.clock')), 403);
        abort_unless($record->posting_status === 'posted', 422, 'Post the approved payroll before issuing a payslip.');
        $remaining = app(FinancePayrollSettlements::class)->remaining($record, 'salary', $line->id);

        return view('finance.payslip', compact('business', 'record', 'line', 'remaining'));
    }

    public function settlement(Request $request, int $run,
        FinancePayrollSettlements $service): RedirectResponse
    {
        $this->check($request, 'finance.payroll.settle');
        $data = $request->validate([
            'kind' => ['required', 'in:salary,withholding'],
            'payroll_line_id' => ['nullable', 'integer', 'min:1'],
            'recipient_name' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999999'],
            'cash_account_key' => ['required', 'in:bank,cash'],
            'bank_profile_id' => ['nullable', 'integer', 'min:1'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'external_reference' => ['required', 'string', 'min:3', 'max:150'],
            'evidence_note' => ['required', 'string', 'min:20', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $business = $request->attributes->get('activeBusiness');
        $run = FinancePayrollRun::query()->where('business_id', $business->id)->findOrFail($run);
        $service->prepare($business, $run, $data, $request->user()->id);

        return redirect()->route('finance.payroll.show', $run->id)->with('status',
            'Manual settlement evidence recorded. A separate finance reviewer must approve posting before it reduces payroll liabilities.');
    }

    public function withdrawCorrection(Request $request, int $run,
        FinancePayrollCorrections $service): RedirectResponse
    {
        $this->check($request, 'finance.payroll.correct');
        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:2000']]);
        $business = $request->attributes->get('activeBusiness');
        $run = FinancePayrollRun::query()->where('business_id', $business->id)->findOrFail($run);
        $reversal = FinancePayrollReversal::query()->where('business_id', $business->id)
            ->where('payroll_run_id', $run->id)->firstOrFail();
        $service->withdraw($reversal, $request->user()->id, $data['reason']);

        return redirect()->route('finance.payroll.show', $run->id)->with('status',
            'Unposted correction withdrawn with an audit note. Payroll settlements may now be proposed again.');
    }

    public function correction(Request $request, int $run,
        FinancePayrollCorrections $service): RedirectResponse
    {
        $this->check($request, 'finance.payroll.correct');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:30', 'max:3000'],
        ]);
        $business = $request->attributes->get('activeBusiness');
        $run = FinancePayrollRun::query()->where('business_id', $business->id)->findOrFail($run);
        $service->prepare($business, $run, $data['reason'], $request->user()->id);

        return redirect()->route('finance.payroll.show', $run->id)->with('status',
            'Payroll correction prepared. The original remains intact until an independent reversal journal is approved and posted.');
    }
}
