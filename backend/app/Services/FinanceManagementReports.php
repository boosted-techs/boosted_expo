<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessAsset;
use App\Models\CashClose;
use App\Models\CashCustody;
use App\Models\CommercialInvoice;
use App\Models\ExportShipment;
use App\Models\FinanceAccount;
use App\Models\FinanceBankLedgerCheck;
use App\Models\FinanceBankProfile;
use App\Models\FinanceBankStatement;
use App\Models\FinanceCutover;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceOpeningItem;
use App\Models\FinancePayrollRun;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceWorkflow;
use App\Models\FinanceWorkflowLeg;
use App\Models\LoanFacility;
use App\Models\LoanInstalment;
use App\Models\PackingWasteEntry;
use App\Models\RecurringObligation;
use App\Models\StaffAttendance;
use App\Models\StaffLeave;
use App\Models\StaffOutput;
use App\Models\StaffPayAdjustment;
use App\Models\StaffPayRate;
use App\Models\StaffShift;
use App\Models\StockExceptionCase;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FinanceManagementReports
{
    private array $sourceParents = [];

    private array $privateSources = [];

    private function sourceEvidence(FinanceJournal $journal): ?array
    {
        $models = ['stock_adjustment' => 'StockAdjustment', 'register_entry' => 'FinanceRegisterEntry', 'money_operation' => 'FinanceOperation', 'commercial_invoice' => 'CommercialInvoice', 'supplier_bill' => 'FinanceSupplierBill', 'payment' => 'FinancePayment', 'shipment_cost' => 'FinanceShipmentCost', 'inventory_relief' => 'FinanceInventoryRelief', 'credit_note' => 'FinanceCreditNote', 'opening_balance' => 'FinanceOpeningBalance', 'balance_adjustment' => 'FinanceBalanceAdjustment', 'payroll_run' => 'FinancePayrollRun', 'payroll_settlement' => 'FinancePayrollSettlement', 'payroll_reversal' => 'FinancePayrollReversal', 'transaction_reversal' => 'FinanceTransactionReversal', 'foreign_bank_opening' => 'FinanceForeignBankOpening', 'fx_revaluation' => 'FinanceFxRevaluation', 'partial_bank_return' => 'FinancePartialBankReturn', 'monetary_revaluation' => 'FinanceMonetaryRevaluation', 'workflow_leg' => 'FinanceWorkflowLeg'];
        if (! isset($models[$journal->source_type])) {
            return null;
        }
        $model = 'App\\Models\\'.$models[$journal->source_type];
        $record = $model::query()->where('business_id', $journal->business_id)->find($journal->source_id);
        if (! $record) {
            return ['missing_source' => true, 'type' => $journal->source_type, 'id' => $journal->source_id];
        }
        foreach (($record->data_snapshot['evidence'] ?? []) as $file) {
            $this->privateSources[$file['path']] = $file;
        }
        $raw = $record->getAttributes();
        foreach (['status', 'posting_status', 'posted_at', 'updated_at', 'created_at'] as $key) {
            unset($raw[$key]);
        }
        ksort($raw);
        $evidence = ['type' => $journal->source_type, 'id' => $journal->source_id, 'sha256' => app(FinanceWorkflows::class)->hash($raw)];
        if ($journal->source_type === 'workflow_leg') {
            $meta = DB::table('finance_workflows')->where('business_id', $journal->business_id)->where('id', $record->workflow_id)->first(['id', 'business_id', 'reference', 'kind', 'event_on', 'currency', 'amount', 'rate_to_base', 'exchange_rate_id', 'snapshot_hash', 'created_by', 'external_reference', 'reason']);
            $cacheKey = app(FinanceWorkflows::class)->hash((array) $meta);
            if (! isset($this->sourceParents[$cacheKey])) {
                $parent = FinanceWorkflow::query()->where('business_id', $journal->business_id)->findOrFail($record->workflow_id);
                foreach (($parent->data_snapshot['evidence'] ?? []) as $file) {
                    $this->privateSources[$file['path']] = $file;
                }
                $parentRaw = $parent->getAttributes();
                foreach (['status', 'posting_status', 'posted_at', 'updated_at', 'created_at'] as $key) {
                    unset($parentRaw[$key]);
                }
                ksort($parentRaw);
                $this->sourceParents[$cacheKey] = app(FinanceWorkflows::class)->hash($parentRaw);
            }
            $evidence['parent_sha256'] = $this->sourceParents[$cacheKey];
        }

        return $evidence;
    }

    public function journalEvidence(FinanceJournal $journal): array
    {
        $entry = $journal->only(['business_id', 'reference', 'source_type', 'source_id', 'currency', 'base_currency', 'description', 'created_by', 'approved_by']);
        $entry['posted_on'] = $journal->posted_on->toDateString();
        $entry['rate_to_base'] = (string) BigDecimal::of((string) $journal->rate_to_base)->toScale(10);
        $entry['source_evidence'] = $this->sourceEvidence($journal);
        $entry['lines'] = [];
        foreach (($journal->relationLoaded('lines') ? $journal->lines->sortBy('id') : $journal->lines()->orderBy('id')->get()) as $line) {
            $row = $line->only(['id', 'business_id', 'account_id', 'memo']);
            foreach (['debit', 'credit', 'base_debit', 'base_credit'] as $key) {
                $row[$key] = (string) BigDecimal::of((string) $line->{$key})->toScale(2);
            }
            $entry['lines'][] = $row;
        }

        return $entry;
    }

    public function reconciliation(Business $business, string $through): array
    {
        $this->sourceParents = [];
        $this->privateSources = [];
        $issues = [];
        $checked = 0;
        $journalDates = [];
        foreach (FinanceJournal::query()->where('business_id', $business->id)->whereDate('posted_on', '<=', $through)->with('lines')->orderBy('id')->lazyById(100) as $journal) {
            $journalDates[$journal->source_type.':'.$journal->source_id] = true;
            if (! $journal->source_hash) {
                $issues[] = ['kind' => 'historical_evidence', 'reference' => $journal->reference, 'note' => 'Historical journal needs independently signed cutover evidence.'];

                continue;
            }
            $actual = $this->journalEvidence($journal);
            if ($actual['source_evidence']['missing_source'] ?? false) {
                $issues[] = ['kind' => 'missing_source', 'reference' => $journal->reference, 'note' => 'The original financial source record is missing.'];
            }
            if (! hash_equals($journal->source_hash, app(FinanceWorkflows::class)->hash($journal->source_snapshot)) || $actual !== $journal->source_snapshot) {
                $issues[] = ['kind' => 'changed_posting', 'reference' => $journal->reference, 'note' => 'Posted journal or lines differ from the frozen posting evidence.'];
            }
            $checked++;
        }
        $disk = Storage::disk('local');
        foreach ($this->privateSources as $path => $file) {
            $validPrefix = collect(['workflow-evidence/', 'register-evidence/', 'finance-evidence/'])->contains(fn ($prefix) => str_starts_with($path, $prefix.$business->id.'/'));
            if (! $validPrefix || ! $disk->exists($path) || ! hash_equals($file['sha256'], hash_file('sha256', $disk->path($path)))) {
                $issues[] = ['kind' => 'missing_evidence', 'reference' => $file['name'] ?? 'Private financial document', 'note' => 'Original private evidence is missing or changed after posting.'];
            }
        }
        foreach (FinanceCutover::query()->where('business_id', $business->id)->whereIn('status', ['posted', 'accepted'])->whereDate('effective_on', '<=', $through)->get() as $cutover) {
            $path = $cutover->source_file_path;
            if (! $path || ! str_starts_with($path, 'cutover-evidence/'.$business->id.'/') || ! $disk->exists($path) || ! hash_equals((string) $cutover->source_file_sha256, hash_file('sha256', $disk->path($path)))) {
                $issues[] = ['kind' => 'missing_cutover_evidence', 'reference' => $cutover->reference, 'note' => 'The original signed opening file is missing or changed.'];
            }
        }
        $postedByDate = fn (string $type, int $id): bool => isset($journalDates[$type.':'.$id]);
        foreach (ExportShipment::query()->where('business_id', $business->id)->whereIn('status', ['dispatched', 'delivered'])->whereDate('actual_departure_at', '<=', $through)->with('salesOrder')->lazyById(100) as $shipment) {
            $relief = FinanceInventoryRelief::query()->where('business_id', $business->id)->where('shipment_id', $shipment->id)->first();
            if (! $relief || ! $postedByDate('inventory_relief', $relief->id)) {
                $issues[] = ['kind' => 'unvalued_dispatch', 'reference' => $shipment->reference, 'note' => 'Dispatched stock has no COGS posting by the reporting date.'];
            }
            if ($shipment->salesOrder && $shipment->salesOrder->order_type !== 'replacement') {
                $invoice = CommercialInvoice::query()->where('business_id', $business->id)->where('shipment_id', $shipment->id)->first();
                if (! $invoice || ! $postedByDate('commercial_invoice', $invoice->id)) {
                    $issues[] = ['kind' => 'unrecognised_sale', 'reference' => $shipment->reference, 'note' => 'Delivered/dispatched sale has no revenue posting by the reporting date.'];
                }
            }
        }
        foreach (PackingWasteEntry::query()->where('business_id', $business->id)->whereDate('created_at', '<=', $through)->lazyById(100) as $waste) {
            $relief = FinanceInventoryRelief::query()->where('business_id', $business->id)->where('waste_entry_id', $waste->id)->first();
            if (! $relief || ! $postedByDate('inventory_relief', $relief->id)) {
                $issues[] = ['kind' => 'unvalued_waste', 'reference' => 'Waste #'.$waste->id, 'note' => 'Observed produce loss has no cost posting by this date.'];
            }
        }
        $unbilled = DB::table('goods_receipts as gr')->where('gr.business_id', $business->id)->whereDate('gr.received_on', '<=', $through)->groupBy('gr.purchase_order_id')->havingRaw('ROUND(SUM(gr.accepted_quantity),3) > ROUND((SELECT COALESCE(SUM(bill.accepted_quantity),0) FROM finance_supplier_bills bill WHERE bill.business_id = ? AND bill.purchase_order_id = gr.purchase_order_id AND bill.posting_status = ? AND bill.vendor_invoice_date <= ?),3)', [$business->id, 'posted', $through])->pluck('gr.purchase_order_id');
        foreach ($unbilled as $id) {
            $issues[] = ['kind' => 'unbilled_receipt', 'reference' => 'Purchase #'.$id, 'note' => 'Accepted produce is not completely covered by posted bills.'];
        }
        foreach (StockExceptionCase::query()->where('business_id', $business->id)->whereIn('kind', ['customer_return', 'supplier_return'])->whereDate('event_on', '<=', $through)->whereIn('status', ['pending', 'finance_pending'])->cursor() as $case) {
            $issues[] = ['kind' => 'unfinished_return', 'reference' => $case->reference, 'note' => 'Observed physical return is awaiting quantity/value review and posting.'];
        }
        foreach (BusinessAsset::query()->where('business_id', $business->id)->where('ownership', 'owned')->cursor() as $asset) {
            $position = app(FinanceRegisterPostings::class)->position($asset, $through);
            $state = app(AssetRegister::class)->state($asset, $position);
            if (! $state['cost']->isGreaterThan(0) || $state['disposed']) {
                continue;
            }
            if (! $asset->useful_life_months || ! $asset->depreciation_start_on) {
                $issues[] = ['kind' => 'asset_policy', 'reference' => $asset->asset_code, 'note' => 'Owned equipment requires a reviewed depreciation policy.'];

                continue;
            }
            $next = $asset->depreciation_start_on->copy()->addMonthsNoOverflow($state['months'])->endOfMonth()->toDateString();
            if ($state['months'] < $asset->useful_life_months && $next <= $through) {
                $issues[] = ['kind' => 'depreciation_due', 'reference' => $asset->asset_code, 'note' => 'Depreciation is due through '.$next.'.'];
            }
        }
        foreach (FinanceRegisterEntry::query()->where('business_id', $business->id)->where('kind', 'loan_fee_accrual')->where('posting_status', 'posted')->whereDate('event_on', '<=', $through)->cursor() as $fee) {
            $policy = $fee->data_snapshot['details'];
            if (($policy['fee_policy'] ?? null) === 'expense') {
                continue;
            }
            $start = $policy['amortization_start_on'] ?? null;
            if ($start) {
                $count = FinanceRegisterEntry::query()->where('business_id', $business->id)->where('kind', 'loan_fee_amortization')->where('original_entry_id', $fee->id)->where('posting_status', 'posted')->whereDate('event_on', '<=', $through)->count();
                $next = Carbon::parse($start)->addMonthsNoOverflow($count)->endOfMonth()->toDateString();
                if ($count < ($policy['amortization_months'] ?? 0) && $next <= $through) {
                    $issues[] = ['kind' => 'fee_allocation_due', 'reference' => $fee->reference, 'note' => 'Borrowing fee allocation is due through '.$next.'.'];
                }
            }
        }
        foreach (RecurringObligation::query()->where('business_id', $business->id)->where('active', true)->whereDate('next_due_on', '<=', $through)->cursor() as $obligation) {
            $issues[] = ['kind' => 'unrecorded_obligation', 'reference' => $obligation->name, 'note' => 'A due recurring bill still needs recognition or documented pause/correction.'];
        }
        $accountIds = DB::table('finance_journal_lines as l')->join('finance_journals as j', 'j.id', '=', 'l.journal_id')->where('l.business_id', $business->id)->where('j.business_id', $business->id)->whereDate('j.posted_on', '<=', $through)->distinct()->pluck('l.account_id')->all();
        foreach (CashCustody::query()->where('business_id', $business->id)->whereIn('account_id', $accountIds)->cursor() as $cash) {
            $count = CashClose::query()->where('business_id', $business->id)->where('custody_id', $cash->id)->where('status', 'closed')->whereDate('counted_on', '>=', $through)->latest('counted_on')->latest('id')->first();
            if (! $count || ! app(FinanceTreasury::class)->countIsCurrent($count)) {
                $issues[] = ['kind' => 'cash_proof', 'reference' => $cash->name, 'note' => 'An independently closed cash count on/after this reporting date is missing or its original evidence is stale.'];
            }
        }
        foreach (FinanceBankProfile::query()->where('business_id', $business->id)->whereIn('account_id', $accountIds)->cursor() as $bank) {
            $verified = false;
            foreach (FinanceBankStatement::query()->where('business_id', $business->id)->where('account_id', $bank->account_id)->where('status', 'reconciled')->whereDate('period_to', '>=', $through)->orderByDesc('period_to')->cursor() as $statement) {
                $proof = FinanceBankLedgerCheck::query()->where('business_id', $business->id)->where('statement_id', $statement->id)->first();
                if ($proof && app(FinanceBankLedgerProofs::class)->isCurrent($proof)) {
                    $verified = true;
                    break;
                }
            }
            if (! $verified) {
                $issues[] = ['kind' => 'bank_proof', 'reference' => $bank->label, 'note' => 'A reconciled bank statement covering this date still needs independent, current proof against its ledger.'];
            }
        }
        foreach (FinanceAccount::query()->where('business_id', $business->id)->whereIn('system_key', ['cash', 'bank'])->whereIn('id', $accountIds)->cursor() as $legacy) {
            $mapped = CashCustody::query()->where('business_id', $business->id)->where('account_id', $legacy->id)->exists() || FinanceBankProfile::query()->where('business_id', $business->id)->where('account_id', $legacy->id)->exists();
            if (! $mapped) {
                $issues[] = ['kind' => 'unidentified_money', 'reference' => $legacy->name, 'note' => 'Assign the actual custodian or a verified single-currency bank identity to this historical ledger before closing. Mixed accounts require a reconciled cutover.'];
            }
        }
        $runIds = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', 'payroll_run')->whereDate('posted_on', '<=', $through)->whereIn('source_id', FinancePayrollRun::query()->where('business_id', $business->id)->where('posting_status', 'posted')->select('id'))->pluck('source_id')->all();
        $claimed = fn (string $type) => DB::table('finance_payroll_input_claims')->where('business_id', $business->id)->where('source_type', $type)->whereIn('payroll_run_id', $runIds)->select('source_id');
        $end = Carbon::parse($through, $business->timezone)->endOfDay()->utc()->format('Y-m-d H:i:s');
        $attendance = StaffAttendance::query()->where('business_id', $business->id)->whereNotNull('approved_at')->whereRaw('COALESCE(effective_in_at,clocked_in_at) <= ?', [$end])->whereNotIn('id', $claimed('attendance'))->whereNotIn('id', DB::table('finance_payroll_time_claims')->where('business_id', $business->id)->whereIn('payroll_run_id', $runIds)->select('staff_attendance_id'))->count();
        if ($attendance) {
            $issues[] = ['kind' => 'unrecorded_wages', 'reference' => 'Approved attendance', 'note' => $attendance.' approved time entries are not connected to wages recognised by this date.'];
        }
        foreach ([['output', StaffOutput::class, 'worked_on'], ['adjustment', StaffPayAdjustment::class, 'effective_on']] as [$type, $model, $date]) {
            $count = $model::query()->where('business_id', $business->id)->where('status', 'approved')->whereDate($date, '<=', $through)->whereNotIn('id', $claimed($type))->count();
            if ($count) {
                $issues[] = ['kind' => 'unrecorded_wages', 'reference' => ucfirst($type).' pay inputs', 'note' => $count.' approved pay inputs still need payroll recognition by this date.'];
            }
        }
        $cutoverDate = FinanceCutover::query()->where('business_id', $business->id)->where('status', 'accepted')->max('effective_on');
        $coveredRuns = FinancePayrollRun::query()->where('business_id', $business->id)->where('posting_status', 'posted')->whereIn('id', $runIds)->with('lines')->get();
        foreach (StaffPayRate::query()->where('business_id', $business->id)->where('basis', 'monthly')->where('status', 'approved')->whereDate('starts_on', '<=', $through)->with('person')->lazyById(100) as $rate) {
            $start = $rate->starts_on->toDateString();
            if ($cutoverDate) {
                $start = max($start, Carbon::parse($cutoverDate)->addDay()->toDateString());
            }
            $endDate = min($through, $rate->ends_on->toDateString(), $rate->superseded_on ? $rate->superseded_on->copy()->subDay()->toDateString() : $through);
            if ($start > $endDate) {
                continue;
            }
            $months = [];
            for ($day = Carbon::parse($start); $day->toDateString() <= $endDate; $day->addDay()) {
                $date = $day->toDateString();
                $covered = false;
                foreach ($coveredRuns as $run) {
                    if ($run->period_from->toDateString() <= $date && $run->period_to->toDateString() >= $date && $run->lines->contains(fn ($line) => $line->staff_person_id === $rate->staff_person_id || ($rate->person?->user_id && $line->user_id === $rate->person->user_id))) {
                        $covered = true;
                        break;
                    }
                }
                if (! $covered) {
                    $months[$day->format('Y-m')][$date] = BigDecimal::zero();
                }
            }
            $utcStart = Carbon::parse($start, $business->timezone)->startOfDay()->utc();
            $utcEnd = Carbon::parse($endDate, $business->timezone)->endOfDay()->utc();
            foreach ([StaffLeave::query()->where('status', 'approved'), StaffShift::query()->where('status', 'absent')] as $query) {
                foreach ($query->where('business_id', $business->id)->where('staff_person_id', $rate->staff_person_id)->where('pay_treatment', 'unpaid')->whereNotNull('pay_reviewed_by')->where('starts_at', '<=', $utcEnd)->where('ends_at', '>', $utcStart)->get() as $absence) {
                    $first = $absence->starts_at->copy()->timezone($business->timezone)->startOfDay();
                    $last = $absence->ends_at->copy()->subSecond()->timezone($business->timezone)->startOfDay();
                    $days = (int) $first->diffInDays($last) + 1;
                    $units = BigDecimal::of((string) $absence->unpaid_day_units)->dividedBy($days, 12, RoundingMode::HalfUp);
                    for ($day = $first; $day->lte($last); $day->addDay()) {
                        $month = $day->format('Y-m');
                        $date = $day->toDateString();
                        if (isset($months[$month][$date])) {
                            $months[$month][$date] = $months[$month][$date]->plus($units);
                        }
                    }
                }
            }
            foreach ($months as $month => $days) {
                $unpaid = BigDecimal::zero();
                foreach ($days as $units) {
                    $unpaid = $unpaid->plus($units);
                }
                $calendar = Carbon::parse($month.'-01')->daysInMonth;
                $base = BigDecimal::of((string) $rate->amount)->multipliedBy(count($days))->dividedBy($calendar, 12, RoundingMode::HalfUp);
                $deduction = BigDecimal::of((string) $rate->amount)->multipliedBy($unpaid)->dividedBy((string) ($rate->unpaid_day_divisor ?? $calendar), 12, RoundingMode::HalfUp);
                $earned = app(FinanceMath::class)->money($base->minus($deduction));
                if (! $earned->isZero()) {
                    $issues[] = ['kind' => 'monthly_wage_coverage', 'reference' => ($rate->person?->name ?? 'Staff #'.$rate->staff_person_id).' · '.$month, 'note' => $earned->isLessThan(0) ? 'Unpaid salary-day policy needs correction before closing.' : 'Monthly agreed earnings for uncovered days still need payroll recognition by this date.'];
                }
            }
        }
        $inventory = app(FinanceInventoryReconciliation::class)->report($business, $through);
        foreach ($inventory['issues'] as $issue) {
            $issues[] = ['kind' => 'inventory_reconciliation', 'reference' => 'Stock and material controls', 'note' => $issue];
        }

        return ['inventory' => $inventory, 'issues' => $issues, 'checked' => $checked, 'matches' => $issues === []];
    }

    public function maturity(Business $business, string $through, array $statements): array
    {
        $horizon = Carbon::parse($through)->addYearNoOverflow()->toDateString();
        $rows = [];
        $loanCurrent = ['loans_receivable' => BigDecimal::zero(), 'loan_interest_receivable' => BigDecimal::zero(), 'loan_fees_receivable' => BigDecimal::zero(), 'loan_principal' => BigDecimal::zero(), 'owner_borrowing' => BigDecimal::zero(), 'loan_interest_payable' => BigDecimal::zero(), 'loan_fees_payable' => BigDecimal::zero()];
        $loanLong = $loanCurrent;
        $loanUnknown = $loanCurrent;
        foreach (LoanFacility::query()->where('business_id', $business->id)->cursor() as $loan) {
            $state = app(LoanRegister::class)->state($loan, app(FinanceRegisterPostings::class)->position($loan, $through));
            $terms = app(LoanAgreementAmendments::class)->terms($loan, $through);
            $date = $terms['matures_on'];
            $currentPrincipal = BigDecimal::zero();
            foreach (LoanInstalment::query()->where('business_id', $business->id)->where('loan_id', $loan->id)->where('status', 'active')->whereDate('due_on', '<=', $horizon)->cursor() as $instalment) {
                $paid = BigDecimal::zero();
                foreach ($state['entries']->where('kind', 'loan_repayment') as $payment) {
                    if ($payment->instalment_id === $instalment->id) {
                        $paid = $paid->plus($payment->data_snapshot['details']['principal'] ?? '0');
                    }
                }
                foreach ($state['entries']->where('kind', 'register_partial_return') as $returned) {
                    if ($returned->instalment_id === $instalment->id) {
                        $paid = $paid->minus($returned->data_snapshot['details']['component_returns']['principal'] ?? '0');
                    }
                }
                $currentPrincipal = $currentPrincipal->plus(BigDecimal::max('0', BigDecimal::of((string) $instalment->principal)->minus($paid)));
            }
            $principal = $state['principal'];
            $currentPrincipal = $date && $date <= $horizon ? $principal : BigDecimal::min($principal, $currentPrincipal);
            $currentBase = $principal->isGreaterThan(0) ? app(FinanceMath::class)->money($state['principal_base']->multipliedBy($currentPrincipal)->dividedBy($principal, 14, RoundingMode::HalfUp)) : BigDecimal::zero();
            $key = app(LoanRegister::class)->account($loan);
            $loanCurrent[$key] = $loanCurrent[$key]->plus($currentBase);
            if ($date) {
                $loanLong[$key] = $loanLong[$key]->plus($state['principal_base']->minus($currentBase));
            } else {
                $loanUnknown[$key] = $loanUnknown[$key]->plus($state['principal_base']->minus($currentBase));
            }
            $interestKey = $loan->loan_direction === 'lend' ? 'loan_interest_receivable' : 'loan_interest_payable';
            $feesKey = $loan->loan_direction === 'lend' ? 'loan_fees_receivable' : 'loan_fees_payable';
            $loanCurrent[$interestKey] = $loanCurrent[$interestKey]->plus($state['interest_base']);
            $loanCurrent[$feesKey] = $loanCurrent[$feesKey]->plus($state['fees_base']);
            $rows[] = ['reference' => $loan->reference, 'party' => $loan->lender_name, 'currency' => $loan->currency, 'principal' => (string) $principal, 'base' => (string) $state['principal_base'], 'current_base' => (string) $currentBase, 'matures_on' => $date, 'interest' => (string) $state['interest'], 'fees' => (string) $state['fees']];
        }
        $datedControls = [];
        $tradeAging = [];
        foreach ([['commercial_invoice', CommercialInvoice::class, 'receivable'], ['supplier_bill', FinanceSupplierBill::class, 'payable']] as [$sourceType, $model, $key]) {
            foreach ($model::query()->where('business_id', $business->id)->where('posting_status', 'posted')->cursor() as $document) {
                $position = app(FinanceMonetaryRevaluations::class)->position($business, $sourceType, $document->id, $through, true);
                $amount = BigDecimal::of($position['previous_base']);
                if ($amount->isZero()) {
                    continue;
                }
                $due = $document->due_on?->toDateString();
                $bucket = ! $due ? 'undated' : ($due <= $horizon ? 'current' : 'noncurrent');
                $datedControls[$key][$bucket] = ($datedControls[$key][$bucket] ?? BigDecimal::zero())->plus($amount);
                $days = $due && $due < $through ? (int) Carbon::parse($due)->diffInDays(Carbon::parse($through)) : 0;
                $tradeAging[] = ['reference' => $document->reference, 'kind' => $sourceType, 'currency' => $document->currency, 'amount' => $position['foreign_balance'], 'base' => $position['previous_base'], 'due_on' => $due, 'bucket' => ! $due ? 'missing_date' : ($due >= $through ? 'not_due' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90'))))];
            }
        }
        foreach (FinanceOpeningItem::query()->where('business_id', $business->id)->cursor() as $opening) {
            $position = app(FinanceOpeningItems::class)->position($opening, false, null, $through);
            if ($position['base']->isZero()) {
                continue;
            }
            $key = $position['key'];
            $due = $opening->due_on?->toDateString();
            $bucket = ! $due ? 'undated' : ($due <= $horizon ? 'current' : 'noncurrent');
            $datedControls[$key][$bucket] = ($datedControls[$key][$bucket] ?? BigDecimal::zero())->plus($position['base']);
            if (in_array($opening->kind, ['customer_debt', 'supplier_debt'], true)) {
                $tradeAging[] = ['reference' => $opening->reference, 'kind' => $opening->kind.' opening', 'currency' => $opening->currency, 'amount' => (string) $position['amount'], 'base' => (string) $position['base'], 'due_on' => $due, 'bucket' => ! $due ? 'missing_date' : ($due < $through ? 'overdue_opening' : 'not_due')];
            }
        }
        $categories = ['current_asset' => [], 'noncurrent_asset' => [], 'current_liability' => [], 'noncurrent_liability' => [], 'undated_asset' => [], 'undated_liability' => []];
        $totals = array_fill_keys(array_keys($categories), BigDecimal::zero());
        foreach (['asset', 'liability'] as $type) {
            foreach ($statements['balance_categories'][$type] as $line) {
                $key = $line['account']->system_key;
                $value = BigDecimal::of($line['value']);
                $parts = [];
                if (isset($loanCurrent[$key])) {
                    $parts = ['current_'.$type => $loanCurrent[$key], 'noncurrent_'.$type => $loanLong[$key], 'undated_'.$type => $loanUnknown[$key]->plus($value->minus($loanCurrent[$key])->minus($loanLong[$key])->minus($loanUnknown[$key]))];
                } elseif (isset($datedControls[$key])) {
                    $allocated = BigDecimal::zero();
                    foreach ($datedControls[$key] as $bucket => $amount) {
                        $parts[$bucket.'_'.$type] = $amount;
                        $allocated = $allocated->plus($amount);
                    }
                    $parts['undated_'.$type] = ($parts['undated_'.$type] ?? BigDecimal::zero())->plus($value->minus($allocated));
                } elseif (in_array($key, ['asset_cost', 'asset_accumulated', 'loan_deferred_cost'], true)) {
                    $parts['noncurrent_'.$type] = $value;
                } elseif (in_array($key, ['receivable', 'payable', 'asset_payable', 'supplier_advances', 'staff_advances', 'customer_deposits', 'expense_payable', 'customer_credit', 'supplier_credit_receivable'], true)) {
                    $parts['undated_'.$type] = $value;
                } else {
                    $parts['current_'.$type] = $value;
                }
                foreach ($parts as $category => $amount) {
                    if (! $amount->isZero()) {
                        $categories[$category][] = ['account' => $line['account'], 'value' => (string) $amount->toScale(2)];
                        $totals[$category] = $totals[$category]->plus($amount);
                    }
                }
            }
        }

        return compact('horizon', 'rows', 'categories', 'totals', 'tradeAging');
    }

    public function shipmentCost(ExportShipment $shipment): BigDecimal
    {
        $relief = FinanceInventoryRelief::query()->where('business_id', $shipment->business_id)->where('shipment_id', $shipment->id)->where('posting_status', 'posted')->first();
        if (! $relief) {
            return BigDecimal::zero();
        }
        $amount = BigDecimal::of((string) $relief->base_amount);
        foreach (DB::table('finance_cost_layer_reliefs as r')->join('finance_cost_layers as l', 'l.id', '=', 'r.cost_layer_id')->join('finance_workflows as w', 'w.id', '=', 'l.workflow_id')->where('r.business_id', $shipment->business_id)->where('l.business_id', $shipment->business_id)->where('w.business_id', $shipment->business_id)->where('r.inventory_relief_id', $relief->id)->where('w.posting_status', 'posted')->pluck('r.base_cost') as $cost) {
            $amount = $amount->plus((string) $cost);
        }
        foreach (StockExceptionCase::query()->where('business_id', $shipment->business_id)->where('shipment_id', $shipment->id)->where('kind', 'customer_return')->whereHas('workflow', fn ($q) => $q->where('posting_status', 'posted'))->with('workflow')->cursor() as $returned) {
            $amount = $amount->minus($returned->workflow->data_snapshot['details']['value']['base_cost']);
        }

        return $amount;
    }

    public function activity(Business $business, string $from, string $to, array $filters = []): array
    {
        $money = FinanceAccount::query()->where('business_id', $business->id)->where('type', 'asset')->where(fn ($q) => $q->whereIn('system_key', ['cash', 'bank'])->orWhere('system_key', 'like', 'bank_%')->orWhere('system_key', 'like', 'cash_%'))->pluck('id')->all();
        $query = FinanceJournal::query()->where('business_id', $business->id)->whereDate('posted_on', '>=', $from)->whereDate('posted_on', '<=', $to)->when($filters['currency'] ?? null, fn ($q, $v) => $q->where('currency', $v))->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('description', 'like', '%'.$v.'%')->orWhere('reference', 'like', '%'.$v.'%')))->with('lines.account')->orderBy('posted_on')->orderBy('id');
        $rows = [];
        $cash = [];
        $expenses = BigDecimal::zero();
        $opening = [];
        foreach (DB::table('finance_journal_lines as l')->join('finance_journals as j', 'j.id', '=', 'l.journal_id')->where('l.business_id', $business->id)->where('j.business_id', $business->id)->whereIn('l.account_id', $money)->whereDate('j.posted_on', '<', $from)->when($filters['currency'] ?? null, fn ($q, $v) => $q->where('j.currency', $v))->when($filters['account_id'] ?? null, fn ($q, $v) => $q->where('l.account_id', $v))->get(['l.account_id', 'j.currency', 'l.debit', 'l.credit', 'l.base_debit', 'l.base_credit']) as $line) {
            $key = $line->account_id.':'.$line->currency;
            $opening[$key] ??= ['account_id' => $line->account_id, 'currency' => $line->currency, 'units' => BigDecimal::zero(), 'base' => BigDecimal::zero()];
            $opening[$key]['units'] = $opening[$key]['units']->plus((string) $line->debit)->minus((string) $line->credit);
            $opening[$key]['base'] = $opening[$key]['base']->plus((string) $line->base_debit)->minus((string) $line->base_credit);
        }
        foreach ($query->lazy(100) as $journal) {
            $flow = $journal->source_type === 'workflow_leg' ? FinanceWorkflowLeg::query()->where('business_id', $business->id)->with('workflow')->find($journal->source_id)?->workflow : null;
            $isOpening = in_array($journal->source_type, ['opening_balance', 'foreign_bank_opening'], true) || $flow?->kind === 'cutover_opening';
            $cashLines = $journal->lines->whereIn('account_id', $money);
            $otherKeys = $journal->lines->whereNotIn('account_id', $money)->pluck('account.system_key')->all();
            $category = match (true) {
                $isOpening => 'opening',
                $journal->source_type === 'fx_revaluation' || $flow?->kind === 'cash_variance' => 'book_adjustment',
                $cashLines->count() > 1 || in_array('fx_clearing', $otherKeys, true) => 'transfer_exchange',
                count(array_intersect($otherKeys, ['loans_receivable', 'loan_interest_receivable', 'loan_fees_receivable', 'loan_principal', 'owner_borrowing', 'owner_capital', 'owner_drawings'])) > 0 => 'financing',
                count(array_intersect($otherKeys, ['asset_cost', 'asset_payable', 'asset_disposal_gain', 'asset_disposal_loss'])) > 0 => 'equipment',
                default => 'operations',
            };
            foreach ($journal->lines as $line) {
                if (! empty($filters['account_id']) && $line->account_id !== (int) $filters['account_id']) {
                    continue;
                }
                if (! empty($filters['category']) && $filters['category'] !== $category) {
                    continue;
                }
                $isMoney = in_array($line->account_id, $money, true);
                $expense = $line->account->type === 'expense' ? BigDecimal::of((string) $line->base_debit)->minus((string) $line->base_credit) : BigDecimal::zero();
                if (! $isMoney && $expense->isZero()) {
                    continue;
                }
                $row = ['date' => $journal->posted_on->toDateString(), 'journal_id' => $journal->id, 'reference' => $journal->reference, 'source' => $journal->source_type.':'.$journal->source_id, 'description' => $journal->description, 'account' => $line->account->name, 'account_id' => $line->account_id, 'currency' => $journal->currency, 'debit' => (string) $line->debit, 'credit' => (string) $line->credit, 'base_debit' => (string) $line->base_debit, 'base_credit' => (string) $line->base_credit, 'expense' => (string) $expense, 'category' => $category, 'is_money' => $isMoney, 'is_actual_money' => $isMoney && ! $isOpening && $category !== 'book_adjustment' && (BigDecimal::of((string) $line->debit)->isGreaterThan(0) || BigDecimal::of((string) $line->credit)->isGreaterThan(0))];
                $rows[] = $row;
                $expenses = $expenses->plus($expense);
                if ($isMoney) {
                    $key = $line->account_id.':'.$journal->currency;
                    $cash[$key] ??= ['account' => $line->account->name, 'currency' => $journal->currency, 'opening' => $opening[$key]['units'] ?? BigDecimal::zero(), 'opening_base' => $opening[$key]['base'] ?? BigDecimal::zero(), 'in' => BigDecimal::zero(), 'out' => BigDecimal::zero(), 'adjustment' => BigDecimal::zero(), 'base_change' => BigDecimal::zero()];
                    $cash[$key]['base_change'] = $cash[$key]['base_change']->plus((string) $line->base_debit)->minus((string) $line->base_credit);
                    if ($row['is_actual_money']) {
                        $cash[$key]['in'] = $cash[$key]['in']->plus((string) $line->debit);
                        $cash[$key]['out'] = $cash[$key]['out']->plus((string) $line->credit);
                    } else {
                        $cash[$key]['adjustment'] = $cash[$key]['adjustment']->plus((string) $line->debit)->minus((string) $line->credit);
                    }
                }
            }
        }
        foreach ($opening as $key => $value) {
            if (! isset($cash[$key])) {
                $account = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($value['account_id']);
                $cash[$key] = ['account' => $account->name, 'currency' => $value['currency'], 'opening' => $value['units'], 'opening_base' => $value['base'], 'in' => BigDecimal::zero(), 'out' => BigDecimal::zero(), 'adjustment' => BigDecimal::zero(), 'base_change' => BigDecimal::zero()];
            }
        }
        foreach ($cash as &$row) {
            $row['closing'] = $row['opening']->plus($row['in'])->minus($row['out'])->plus($row['adjustment']);
            $row['closing_base'] = $row['opening_base']->plus($row['base_change']);
        } unset($row);

        return compact('rows', 'cash', 'expenses');
    }
}
