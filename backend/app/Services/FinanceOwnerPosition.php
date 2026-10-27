<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use App\Models\FinanceJournalLine;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceWorkflowLeg;
use App\Models\LoanInstalment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class FinanceOwnerPosition
{
    public function report(Business $business, string $date, string $collectionPercent = '100'): array
    {
        $accounts = FinanceAccount::query()->where('business_id', $business->id)->get()->keyBy('id');
        $balances = [];
        $cashIn = [];
        $cashOut = [];
        $baseCashIn = BigDecimal::zero();
        $baseCashOut = BigDecimal::zero();
        $moneyIds = $accounts->filter(fn ($a) => $a->type === 'asset' && (in_array($a->system_key, ['cash', 'bank'], true) || str_starts_with($a->system_key, 'bank_') || str_starts_with($a->system_key, 'cash_')))->modelKeys();
        $noncashWorkflowLegs = FinanceWorkflowLeg::query()->where('business_id', $business->id)->whereHas('workflow', fn ($q) => $q->whereIn('kind', ['cash_variance', 'cutover_opening']))->pluck('id')->all();
        $lines = FinanceJournalLine::query()->where('finance_journal_lines.business_id', $business->id)->join('finance_journals as j', function ($join) {
            $join->on('j.id', '=', 'finance_journal_lines.journal_id')->on('j.business_id', '=', 'finance_journal_lines.business_id');
        })->whereDate('j.posted_on', '<=', $date)->get(['finance_journal_lines.*', 'j.currency', 'j.posted_on', 'j.source_type', 'j.source_id']);
        foreach ($lines as $line) {
            $key = $accounts[$line->account_id]->system_key;
            $balances[$key] = ($balances[$key] ?? BigDecimal::zero())->plus((string) $line->base_debit)->minus((string) $line->base_credit);
            if (! ($line->source_type === 'workflow_leg' && in_array($line->source_id, $noncashWorkflowLegs, true)) && ! in_array($line->source_type, ['opening_balance', 'foreign_bank_opening'], true) && (BigDecimal::of((string) $line->debit)->isGreaterThan(0) || BigDecimal::of((string) $line->credit)->isGreaterThan(0)) && in_array($line->account_id, $moneyIds, true) && substr((string) $line->posted_on, 0, 10) === $date) {
                $cashIn[$line->currency] = ($cashIn[$line->currency] ?? BigDecimal::zero())->plus((string) $line->debit);
                $cashOut[$line->currency] = ($cashOut[$line->currency] ?? BigDecimal::zero())->plus((string) $line->credit);
                $baseCashIn = $baseCashIn->plus((string) $line->base_debit);
                $baseCashOut = $baseCashOut->plus((string) $line->base_credit);
            }
        }
        $money = BigDecimal::zero();
        foreach ($accounts as $a) {
            if (in_array($a->id, $moneyIds, true)) {
                $money = $money->plus($balances[$a->system_key] ?? 0);
            }
        }
        $unposted = FinanceOperation::query()->where('business_id', $business->id)->where('posting_status', 'not_posted')->whereDate('event_on', '<=', $date)->count();
        $reviews = FinancePostingRequest::query()->where('business_id', $business->id)->whereIn('status', ['pending', 'approved'])->count();
        $funding = [];
        $missingTerms = ['customer' => 0, 'supplier' => 0];
        $today = now($business->timezone)->toDateString();
        $overdue = [];
        foreach ([true, false] as $customer) {
            foreach (app(FinanceDocuments::class)->outstandingQuery($business, $customer)->orderBy('id')->cursor() as $document) {
                $kind = $customer ? 'customer' : 'supplier';
                $amount = app(FinanceDocuments::class)->outstanding($document);
                if (! $document->due_on) {
                    $missingTerms[$kind]++;

                    continue;
                }
                $due = $document->due_on->toDateString();
                if ($due < $today) {
                    $overdue[] = ['kind' => $kind, 'document' => $document, 'amount' => $amount];
                }
                foreach ([7, 30] as $days) {
                    if ($due > now($business->timezone)->addDays($days)->toDateString()) {
                        continue;
                    }
                    $currency = $document->currency;
                    $funding[$days][$currency] ??= ['collections' => BigDecimal::zero(), 'payments' => BigDecimal::zero(), 'expenses' => BigDecimal::zero(), 'loans' => BigDecimal::zero()];
                    if ($customer) {
                        $funding[$days][$currency]['collections'] = $funding[$days][$currency]['collections']->plus($amount->multipliedBy($collectionPercent)->dividedBy(100, 2, RoundingMode::HalfUp));
                    } else {
                        $funding[$days][$currency]['payments'] = $funding[$days][$currency]['payments']->plus($amount);
                    }
                }
            }
        }
        foreach (FinanceOperation::query()->where('business_id', $business->id)->whereIn('kind', ['expense', 'market_reimbursement'])->where('posting_status', 'posted')->get() as $expense) {
            $remaining = app(FinanceOperations::class)->remaining($expense);
            if (! $remaining->isGreaterThan(0)) {
                continue;
            }
            if (! $expense->due_on) {
                $missingTerms['expense'] = ($missingTerms['expense'] ?? 0) + 1;

                continue;
            }
            foreach ([7, 30] as $days) {
                if ($expense->due_on->toDateString() <= now($business->timezone)->addDays($days)->toDateString()) {
                    $funding[$days][$expense->currency] ??= ['collections' => BigDecimal::zero(), 'payments' => BigDecimal::zero(), 'expenses' => BigDecimal::zero(), 'loans' => BigDecimal::zero()];
                    $funding[$days][$expense->currency]['expenses'] = $funding[$days][$expense->currency]['expenses']->plus($remaining);
                }
            }
        }
        foreach (LoanInstalment::query()->where('business_id', $business->id)->where('status', 'active')->whereDate('due_on', '<=', now($business->timezone)->addDays(30)->toDateString())->whereHas('loan', fn ($q) => $q->where('status', 'active'))->with('loan')->orderBy('due_on')->get() as $instalment) {
            $parts = app(LoanRegister::class)->instalmentBalances($instalment);
            $total = $parts['principal']->plus($parts['interest'])->plus($parts['fees']);
            foreach ([7, 30] as $days) {
                if ($instalment->due_on->toDateString() <= now($business->timezone)->addDays($days)->toDateString()) {
                    $currency = $instalment->loan->currency;
                    $funding[$days][$currency] ??= ['collections' => BigDecimal::zero(), 'payments' => BigDecimal::zero(), 'expenses' => BigDecimal::zero(), 'loans' => BigDecimal::zero()];
                    $funding[$days][$currency]['loans'] = $funding[$days][$currency]['loans']->plus($total);
                }
            }
        }
        $unallocatedLoanRepayments = FinanceRegisterEntry::query()->where('business_id', $business->id)->where('kind', 'loan_repayment')->where('posting_status', 'posted')->whereNull('instalment_id')->count();
        $registerReconciliation = app(FinanceRegisterReconciliation::class)->report($business, $date, $balances);
        $unaccounted = FinanceOperation::query()->where('business_id', $business->id)->whereIn('kind', ['staff_advance', 'supplier_advance', 'customer_deposit'])->where('posting_status', 'posted')->orderBy('due_on')->get()->map(fn ($operation) => ['operation' => $operation, 'remaining' => app(FinanceOperations::class)->remaining($operation)])->filter(fn ($row) => $row['remaining']->isGreaterThan(0));

        $ownerClaims = FinanceOperation::query()->where('business_id', $business->id)->where('kind', 'expense')->whereNotNull('owner_user_id')->where('posting_status', 'posted')->with('owner')->get()->map(fn ($source) => ['source' => $source, 'remaining' => app(FinanceOperations::class)->remaining($source)])->filter(fn ($r) => $r['remaining']->isGreaterThan(0));

        return compact('ownerClaims', 'balances', 'money', 'cashIn', 'cashOut', 'baseCashIn', 'baseCashOut', 'unposted', 'reviews', 'funding', 'missingTerms', 'today', 'overdue', 'unaccounted', 'unallocatedLoanRepayments', 'registerReconciliation');
    }
}
