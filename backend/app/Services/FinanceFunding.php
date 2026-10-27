<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessAsset;
use App\Models\CashClose;
use App\Models\CashCustody;
use App\Models\FinanceAccount;
use App\Models\FinanceBankLedgerCheck;
use App\Models\FinanceBankProfile;
use App\Models\FinanceBankStatement;
use App\Models\FinanceJournalLine;
use App\Models\FinanceOpeningItem;
use App\Models\FinanceOperation;
use App\Models\FinancePayment;
use App\Models\FinancePayrollRun;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceTradeCredit;
use App\Models\FinanceWorkflowLeg;
use App\Models\FundingCommitment;
use App\Models\LoanFacility;
use App\Models\LoanInstalment;
use App\Models\PurchaseOrder;
use App\Models\RecurringObligation;
use App\Models\StaffAttendance;
use App\Models\StaffOutput;
use App\Models\StaffPayRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceFunding
{
    public function propose(Business $b, array $data, int $actor): FundingCommitment
    {
        return DB::transaction(function () use ($b, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $data['amount'] = (string) app(FinanceMath::class)->money($data['amount']);
            ksort($data);
            $hash = app(FinanceWorkflows::class)->hash($data);
            $old = FundingCommitment::query()->where('business_id', $b->id)->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_unless($old->payload_hash === $hash, 422, 'This planning request already has different details.');

                return $old;
            }

            return FundingCommitment::create([...$data, 'business_id' => $b->id, 'reference' => 'PLAN-'.Str::upper((string) Str::ulid()), 'status' => 'proposed', 'created_by' => $actor, 'payload_hash' => $hash]);
        }, 3);
    }

    public function review(Business $b, FundingCommitment $plan, bool $approve, string $note, int $actor): void
    {
        DB::transaction(function () use ($b, $plan, $approve, $note, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $plan = FundingCommitment::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($plan->id);
            abort_unless($plan->status === 'proposed' && $plan->created_by !== $actor, 422, 'A different authorised person must review the plan.');
            $this->remaining($b, $plan);
            $plan->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note]);
            app(PlatformAccess::class)->audit($b->id, $actor, 'finance.funding_plan_reviewed', $note, [], ['plan_id' => $plan->id, 'status' => $plan->status]);
        }, 3);
    }

    public function cancel(Business $b, FundingCommitment $plan, string $note, int $actor): void
    {
        DB::transaction(function () use ($b, $plan, $note, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $plan = FundingCommitment::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($plan->id);
            abort_unless(in_array($plan->status, ['proposed', 'approved'], true), 422, 'This plan is already closed.');
            $plan->update(['status' => 'cancelled']);
            app(PlatformAccess::class)->audit($b->id, $actor, 'finance.funding_plan_cancelled', $note, [], ['plan_id' => $plan->id]);
        }, 3);
    }

    private function recognised(Business $b, string $type, int $id): array
    {
        $model = match ($type) {
            'expense' => FinanceOperation::class,'payroll' => FinancePayrollRun::class,'asset' => FinanceRegisterEntry::class,default => abort(422, 'Choose an expense, payroll or equipment purchase.')
        };
        $source = $model::query()->where('business_id', $b->id)->findOrFail($id);
        $face = BigDecimal::zero();
        if ($source->posting_status === 'posted') {
            if ($type === 'expense') {
                abort_unless(in_array($source->kind, ['expense', 'paid_expense', 'staff_expense', 'market_reimbursement'], true), 422, 'Choose an actual expense or reimbursement.');
                $face = BigDecimal::of((string) $source->amount)->minus(app(FinanceMoneyCorrections::class)->corrected($source));
            } elseif ($type === 'payroll') {
                $face = BigDecimal::of((string) $source->gross_total)->plus((string) ($source->data_snapshot['statutory_totals']['nssf_employer'] ?? '0'));
            } else {
                abort_unless($source->kind === 'asset_purchase', 422, 'Choose an actual equipment acquisition.');
                $face = BigDecimal::of((string) $source->amount);
                foreach (FinanceRegisterEntry::query()->where('business_id', $b->id)->where('original_entry_id', $source->id)->where('kind', 'register_partial_return')->where('posting_status', 'posted')->pluck('cash_amount') as $returned) {
                    $face = $face->minus((string) $returned);
                }
            }
        }

        return ['currency' => $source->currency, 'face' => $face, 'status' => $source->posting_status, 'source' => $source];
    }

    public function remaining(Business $b, FundingCommitment $plan): BigDecimal
    {
        $proof = $plan->only(['kind', 'currency', 'amount', 'due_on', 'purpose', 'evidence', 'request_key']);
        $proof['amount'] = (string) app(FinanceMath::class)->money((string) $plan->amount);
        $proof['due_on'] = $plan->due_on->toDateString();
        ksort($proof);
        abort_unless(hash_equals($plan->payload_hash, app(FinanceWorkflows::class)->hash($proof)), 422, 'The approved commitment changed. Restore the original planning evidence.');
        $used = BigDecimal::zero();
        foreach (DB::table('funding_recognitions')->where('business_id', $b->id)->where('commitment_id', $plan->id)->get() as $row) {
            $source = $this->recognised($b, $row->source_type, $row->source_id);
            $used = $used->plus(BigDecimal::min((string) $row->amount, $source['face']));
        }

        return BigDecimal::max('0', BigDecimal::of((string) $plan->amount)->minus($used));
    }

    public function recognise(Business $b, FundingCommitment $plan, array $data, int $actor): void
    {
        DB::transaction(function () use ($b, $plan, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $plan = FundingCommitment::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($plan->id);
            abort_unless($plan->status === 'approved', 422, 'Approve the expected commitment before linking an actual source.');
            $old = DB::table('funding_recognitions')->where('business_id', $b->id)->where('source_type', $data['source_type'])->where('source_id', $data['source_id'])->first();
            if ($old) {
                abort_unless($old->commitment_id === $plan->id && BigDecimal::of((string) $old->amount)->isEqualTo($data['amount']) && $old->reason === $data['reason'], 422, 'This actual source already covers another commitment or amount.');

                return;
            }$source = $this->recognised($b, $data['source_type'], (int) $data['source_id']);
            $amount = BigDecimal::of($data['amount']);
            abort_unless($source['status'] === 'posted' && $source['currency'] === $plan->currency && $amount->isGreaterThan(0) && ! $amount->isGreaterThan($source['face']) && ! $amount->isGreaterThan($this->remaining($b, $plan)), 422, 'Link only the matching currency and unrecognised planned amount, within the posted actual source.');
            DB::table('funding_recognitions')->insert([...$data, 'business_id' => $b->id, 'commitment_id' => $plan->id, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            app(PlatformAccess::class)->audit($b->id, $actor, 'finance.funding_plan_recognised', $data['reason'], [], ['plan_id' => $plan->id, 'type' => $data['source_type'], 'source_id' => $data['source_id']]);
        }, 3);
    }

    public function report(Business $b, string $collectionPercent = '100'): array
    {
        $today = now($b->timezone)->toDateString();
        $end = now($b->timezone)->addDays(30)->toDateString();
        $items = [];
        $missing = [];
        $coverage = [];
        $math = app(FinanceMath::class);
        $add = function (string $kind, string $currency, BigDecimal $amount, ?string $due, string $reference, ?string $route = null) use (&$items, &$missing) {
            if (! $amount->isGreaterThan(0)) {
                return;
            }$row = compact('kind', 'currency', 'amount', 'due', 'reference', 'route');
            if (! $due) {
                $missing[] = $row;
            } else {
                $items[] = $row;
            }
        };
        foreach ([true, false] as $customer) {
            foreach (app(FinanceDocuments::class)->outstandingQuery($b, $customer)->orderBy('id')->cursor() as $doc) {
                $add($customer ? 'collections' : 'payments', $doc->currency, app(FinanceDocuments::class)->outstanding($doc), $doc->due_on?->toDateString(), $doc->reference);
            }
        }
        foreach (FinanceOpeningItem::query()->where('business_id', $b->id)->with('workflow')->cursor() as $opening) {
            $state = app(FinanceOpeningItems::class)->position($opening, true);
            if (in_array($opening->kind, ['customer_debt', 'supplier_debt'], true)) {
                $add($opening->kind === 'customer_debt' ? 'collections' : 'payments', $opening->currency, $state['available'], $opening->due_on?->toDateString(), $opening->reference.' · opening debt', route('finance.cutover.index'));
            }
        }
        foreach (FinanceOperation::query()->where('business_id', $b->id)->whereIn('kind', ['expense', 'market_reimbursement'])->where('posting_status', 'posted')->orderBy('id')->cursor() as $expense) {
            $add('expenses', $expense->currency, app(FinanceOperations::class)->remaining($expense), $expense->due_on?->toDateString(), $expense->reference, route('finance.money.show', $expense->id));
        }
        foreach (PurchaseOrder::query()->where('business_id', $b->id)->where('status', '!=', 'cancelled')->with('receipts')->lazyById(100) as $po) {
            $accepted = app(ProcurementMath::class)->sum($po->receipts->pluck('accepted_quantity')->all());
            $target = $po->status === 'closed' ? $accepted : BigDecimal::max((string) $po->quantity, $accepted);
            $billed = app(ProcurementMath::class)->sum(FinanceSupplierBill::query()->where('business_id', $b->id)->where('purchase_order_id', $po->id)->where('posting_status', 'posted')->pluck('accepted_quantity')->all());
            $qty = BigDecimal::max('0', $target->minus($billed));
            if ($qty->isGreaterThan(0)) {
                if ($po->unit_price === null) {
                    $coverage[] = 'Unpriced purchase '.$po->reference;

                    continue;
                }$add('purchases', $po->currency, $math->amount($qty, (string) $po->unit_price), $po->expected_on?->toDateString() ?? ($po->kind === 'market' ? $po->purchased_on?->toDateString() : null), $po->reference);
            }
        }
        foreach (RecurringObligation::query()->where('business_id', $b->id)->where('active', true)->whereDate('next_due_on', '<=', $end)->orderBy('id')->cursor() as $recurring) {
            $due = $recurring->next_due_on->copy();
            for ($n = 0; $n < 100 && $due->toDateString() <= $end; $n++) {
                $add('recurring', $recurring->currency, BigDecimal::of((string) $recurring->amount), $due->toDateString(), $recurring->name);
                $due->addMonthsNoOverflow($recurring->interval_months);
            }
        }
        foreach (FinancePayrollRun::query()->where('business_id', $b->id)->whereIn('posting_status', ['posted', 'not_posted'])->with('lines')->lazyById(100) as $run) {
            $salary = BigDecimal::zero();
            foreach ($run->lines as $line) {
                $salary = $salary->plus($run->posting_status === 'posted' ? app(FinancePayrollSettlements::class)->remaining($run, 'salary', $line->id) : (string) $line->net);
            }$add('wages', $run->currency, $salary, $run->period_to->toDateString(), $run->reference.' · net wages');
            $due = $run->period_to->copy()->addMonthNoOverflow()->startOfMonth()->addDays(14)->toDateString();
            foreach (['paye', 'nssf', 'withholding'] as $kind) {
                $value = $run->posting_status === 'posted' ? app(FinancePayrollSettlements::class)->remaining($run, $kind) : match ($kind) {
                    'paye' => BigDecimal::of((string) ($run->data_snapshot['statutory_totals']['paye'] ?? '0')),'nssf' => BigDecimal::of((string) ($run->data_snapshot['statutory_totals']['nssf_employee'] ?? '0'))->plus((string) ($run->data_snapshot['statutory_totals']['nssf_employer'] ?? '0')),default => BigDecimal::of((string) ($run->data_snapshot['statutory_totals']['manual'] ?? $run->deduction_total))
                };
                $add('taxes', $run->currency, $value, $kind === 'withholding' ? null : $due, $run->reference.' · '.$kind);
            }
        }
        foreach (BusinessAsset::query()->where('business_id', $b->id)->where('ownership', 'owned')->orderBy('id')->cursor() as $asset) {
            $s = app(AssetRegister::class)->state($asset);
            if ($s['origin']) {
                $add('equipment', $s['origin']->currency, $s['payable'], $s['origin']->data_snapshot['details']['due_on'] ?? null, $asset->asset_code.' · '.$asset->name);
            }
        }
        foreach (LoanInstalment::query()->where('business_id', $b->id)->where('status', 'active')->whereHas('loan', fn ($q) => $q->where('status', 'active'))->with('loan')->lazyById(100) as $inst) {
            $parts = app(LoanRegister::class)->instalmentBalances($inst);
            $add($inst->loan->loan_direction === 'lend' ? 'collections' : 'loans', $inst->loan->currency, $parts['principal']->plus($parts['interest'])->plus($parts['fees']), $inst->due_on->toDateString(), $inst->loan->reference);
        }
        foreach (LoanFacility::query()->where('business_id', $b->id)->where('status', 'active')->cursor() as $loan) {
            $state = app(LoanRegister::class)->state($loan);
            $scheduled = ['principal' => BigDecimal::zero(), 'interest' => BigDecimal::zero(), 'fees' => BigDecimal::zero()];
            foreach (LoanInstalment::query()->where('business_id', $b->id)->where('loan_id', $loan->id)->where('status', 'active')->cursor() as $instalment) {
                $parts = app(LoanRegister::class)->instalmentBalances($instalment);
                foreach ($scheduled as $key => $value) {
                    $scheduled[$key] = $value->plus($parts[$key]);
                }
            }
            $terms = app(LoanAgreementAmendments::class)->terms($loan, $today);
            foreach ($scheduled as $key => $value) {
                $unscheduled = BigDecimal::max('0', $state[$key]->minus($value));
                $add($loan->loan_direction === 'lend' ? 'collections' : 'loans', $loan->currency, $unscheduled, $key === 'principal' ? $terms['matures_on'] : null, $loan->reference.' · unscheduled '.$key);
            }
        }
        $output = StaffOutput::query()->where('business_id', $b->id)->where('status', 'approved')->whereNotIn('id', DB::table('finance_payroll_input_claims as c')->join('finance_payroll_runs as r', 'r.id', '=', 'c.payroll_run_id')->where('c.business_id', $b->id)->where('c.source_type', 'output')->whereNotIn('r.posting_status', ['withdrawn', 'reversed'])->select('c.source_id'))->count();
        if ($output) {
            $coverage[] = $output.' approved output entries still need a payroll or an explicit wage plan.';
        }
        $monthly = StaffPayRate::query()->where('business_id', $b->id)->where('basis', 'monthly')->effectiveOn($today)->count();
        if ($monthly) {
            $coverage[] = $monthly.' active monthly agreements require reviewed payrolls or dated wage commitments; unentered wages are not assumed to be zero.';
        }
        foreach (FundingCommitment::query()->where('business_id', $b->id)->where('status', 'approved')->orderBy('id')->cursor() as $plan) {
            $add('planned', $plan->currency, $this->remaining($b, $plan), $plan->due_on->toDateString(), $plan->reference.' · '.$plan->purpose);
        }
        foreach ([['App\\Models\\FinancePayment', 'status', 'pending', 'payment_on'], ['App\\Models\\FinanceShipmentCost', 'status', 'pending', 'paid_on'], ['App\\Models\\FinancePayrollSettlement', 'status', 'pending', 'paid_on'], ['App\\Models\\FinanceOperation', 'posting_status', 'not_posted', 'event_on'], ['App\\Models\\FinanceRegisterEntry', 'posting_status', 'not_posted', 'event_on']] as [$model,$statusField,$pending,$dateField]) {
            foreach ($model::query()->where('business_id', $b->id)->where($statusField, $pending)->orderBy('id')->cursor() as $source) {
                $out = $source instanceof FinancePayment ? $source->direction === 'out' : ($source instanceof FinanceOperation ? in_array($source->kind, ['paid_expense', 'customer_refund', 'supplier_advance', 'staff_advance', 'expense_payment'], true) : ($source instanceof FinanceRegisterEntry ? $source->cash_direction === 'out' : true));
                if ($out) {
                    $add('pending_cash', $source->currency, BigDecimal::of((string) ($source instanceof FinanceRegisterEntry ? $source->cash_amount : $source->amount)), $source->{$dateField}->toDateString(), $source->reference.' · unposted actual payment');
                }
            }
        }
        $moneyKeys = FinanceAccount::query()->where('business_id', $b->id)->where('type', 'asset')->where(fn ($q) => $q->whereIn('system_key', ['cash', 'bank'])->orWhere('system_key', 'like', 'bank_%')->orWhere('system_key', 'like', 'cash_%'))->pluck('system_key')->all();
        foreach (FinanceWorkflowLeg::query()->where('business_id', $b->id)->where('posting_status', 'not_posted')->whereHas('workflow', fn ($q) => $q->where('posting_status', 'not_posted')->whereNotIn('kind', ['cash_variance', 'cutover_opening']))->with('workflow')->lazyById(100) as $leg) {
            $net = BigDecimal::zero();
            foreach ($leg->lines as $line) {
                if (in_array($line['account'], $moneyKeys, true)) {
                    $net = $net->plus($line['debit'])->minus($line['credit']);
                }
            }if ($net->isLessThan(0)) {
                $add('pending_cash', $leg->currency, $net->abs(), $leg->event_on->toDateString(), $leg->workflow->reference.' · unposted money movement');
            }
        }
        foreach (FinanceTradeCredit::query()->where('business_id', $b->id)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'posted'))->with('workflow')->lazyById(100) as $credit) {
            $r = app(FinanceTradeCredits::class)->remaining($credit);
            $add($credit->commercial_invoice_id ? 'refunds' : 'collections', $credit->currency, $r['amount'], $credit->due_on?->toDateString(), $credit->workflow->reference.' · held trade credit');
        }
        $money = [];
        $moneyBase = BigDecimal::zero();
        $bankGaps = [];
        $cashGaps = [];
        foreach (FinanceBankProfile::query()->where('business_id', $b->id)->where('active', true)->with('account')->get() as $bank) {
            $basis = app(FinanceWorkflows::class)->accountBasis($b->id, $bank->account_id, $today);
            $money[$bank->currency] = ($money[$bank->currency] ?? BigDecimal::zero())->plus($basis['units']);
            $moneyBase = $moneyBase->plus($basis['base']);
            $statement = FinanceBankStatement::query()->where('business_id', $b->id)->where('account_id', $bank->account_id)->whereDate('period_to', '<=', $today)->latest('period_to')->latest('id')->first();
            $proof = $statement ? FinanceBankLedgerCheck::query()->where('business_id', $b->id)->where('statement_id', $statement->id)->first() : null;
            if (! $proof || ! app(FinanceBankLedgerProofs::class)->isCurrent($proof) || $statement->period_to->toDateString() < $today) {
                $bankGaps[] = $bank->label;
            }
        }
        foreach (CashCustody::query()->where('business_id', $b->id)->where('active', true)->get() as $cash) {
            $basis = app(FinanceWorkflows::class)->accountBasis($b->id, $cash->account_id, $today);
            $money[$cash->currency] = ($money[$cash->currency] ?? BigDecimal::zero())->plus($basis['units']);
            $moneyBase = $moneyBase->plus($basis['base']);
            if (! CashClose::query()->where('business_id', $b->id)->where('custody_id', $cash->id)->whereDate('counted_on', $today)->where('status', 'closed')->exists()) {
                $cashGaps[] = $cash->name;
            }
        }
        foreach (FinanceAccount::query()->where('business_id', $b->id)->whereIn('system_key', ['cash', 'bank'])->get() as $legacy) {
            $basis = app(FinanceWorkflows::class)->accountBasis($b->id, $legacy->id, $today);
            if (! $basis['units']->isZero() || ! $basis['base']->isZero()) {
                foreach (FinanceJournalLine::query()->where('finance_journal_lines.business_id', $b->id)->where('account_id', $legacy->id)->join('finance_journals as j', 'j.id', '=', 'finance_journal_lines.journal_id')->where('j.business_id', $b->id)->whereDate('j.posted_on', '<=', $today)->get(['j.currency', 'debit', 'credit']) as $line) {
                    $money[$line->currency] = ($money[$line->currency] ?? BigDecimal::zero())->plus((string) $line->debit)->minus((string) $line->credit);
                }
                $moneyBase = $moneyBase->plus($basis['base']);
                $coverage[] = 'Legacy '.$legacy->name.' requires a verified named cash/bank reconciliation; currency units may be mixed.';
            }
        }
        $attendance = StaffAttendance::query()->where('business_id', $b->id)->whereNotNull('approved_at')->whereNotIn('id', DB::table('finance_payroll_input_claims as input')->join('finance_payroll_runs as payroll', 'payroll.id', '=', 'input.payroll_run_id')->where('input.business_id', $b->id)->where('input.source_type', 'attendance')->whereNotIn('payroll.posting_status', ['reversed', 'withdrawn'])->select('input.source_id'))->whereNotIn('id', DB::table('finance_payroll_time_claims as c')->join('finance_payroll_runs as r', 'r.id', '=', 'c.payroll_run_id')->where('c.business_id', $b->id)->whereNotIn('r.posting_status', ['reversed', 'withdrawn'])->select('c.staff_attendance_id'))->count();
        if ($attendance) {
            $coverage[] = $attendance.' approved attendance entries still need a payroll or an explicit wage plan.';
        }
        $windows = [];
        foreach ([7, 30] as $days) {
            $cutoff = now($b->timezone)->addDays($days)->toDateString();
            $currencies = array_keys($money);
            foreach ($items as $item) {
                if ($item['due'] <= $cutoff) {
                    $currencies[] = $item['currency'];
                }
            }foreach (array_unique($currencies) as $currency) {
                $values = array_fill_keys(['collections', 'payments', 'expenses', 'loans', 'purchases', 'recurring', 'wages', 'taxes', 'equipment', 'planned', 'pending_cash', 'refunds'], BigDecimal::zero());
                foreach ($items as $item) {
                    if ($item['currency'] === $currency && $item['due'] <= $cutoff) {
                        $amount = $item['amount'];
                        if ($item['kind'] === 'collections') {
                            $amount = $math->money($amount->multipliedBy($collectionPercent)->dividedBy(100, 14, RoundingMode::HalfUp));
                        }$values[$item['kind']] = $values[$item['kind']]->plus($amount);
                    }
                }$values['opening'] = $money[$currency] ?? BigDecimal::zero();
                $out = BigDecimal::zero();
                foreach ($values as $key => $value) {
                    if (! in_array($key, ['collections', 'opening'], true)) {
                        $out = $out->plus($value);
                    }
                }$values['outgoing'] = $out;
                $values['projected'] = $values['opening']->plus($values['collections'])->minus($out);
                $windows[$days][$currency] = $values;
            }
        }

        return compact('today', 'items', 'missing', 'coverage', 'money', 'moneyBase', 'bankGaps', 'cashGaps', 'windows');
    }
}
