<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashClose;
use App\Models\CommercialInvoice;
use App\Models\FinanceAccount;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceBankLedgerCheck;
use App\Models\FinanceBankProfile;
use App\Models\FinanceBankStatement;
use App\Models\FinanceCreditNote;
use App\Models\FinanceCutover;
use App\Models\FinanceForeignBankOpening;
use App\Models\FinanceFxRevaluation;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinanceMonetaryRevaluation;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceOperation;
use App\Models\FinancePartialBankReturn;
use App\Models\FinancePayment;
use App\Models\FinancePayrollReversal;
use App\Models\FinancePayrollRun;
use App\Models\FinancePayrollSettlement;
use App\Models\FinancePeriod;
use App\Models\FinancePeriodEvent;
use App\Models\FinancePeriodReopenRequest;
use App\Models\FinancePostingRequest;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceTransactionReversal;
use App\Models\FinanceWorkflow;
use App\Models\StockAdjustment;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancePeriodControls
{
    public function assertWritable(Business $business, string $date): void
    {
        abort_if(FinanceCutover::query()->where('business_id', $business->id)->where('status', 'accepted')->whereDate('effective_on', '>=', $date)->exists(), 422, 'The signed opening cutover locks this history. Record a dated reviewed correction after the opening date.');
        if (FinancePeriod::query()->where('business_id', $business->id)
            ->whereIn('status', ['pending', 'closed'])
            ->whereDate('period_to', '>=', $date)->exists()) {
            throw ValidationException::withMessages(['posting_on' => 'This posting date is locked by a pending or closed financial period.']);
        }
    }

    public function create(Business $business, string $from, string $to, int $actor): FinancePeriod
    {
        return DB::transaction(function () use ($business, $from, $to, $actor) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            if ($from > $to || $to > now($business->timezone)->toDateString() ||
                substr($from, 0, 7) !== substr($to, 0, 7)) {
                throw ValidationException::withMessages(['period_to' => 'Select dates within one calendar month, ending no later than today.']);
            }
            if (FinancePeriod::query()->where('business_id', $business->id)
                ->where('status', 'closed')->whereDate('period_to', '>=', $from)->exists()) {
                throw ValidationException::withMessages(['period_from' => 'You cannot create a new period before or within a period that was already closed.']);
            }
            if (FinancePeriod::query()->where('business_id', $business->id)
                ->whereDate('period_from', '<=', $to)
                ->whereDate('period_to', '>=', $from)->exists()) {
                throw ValidationException::withMessages(['period_from' => 'This accounting period overlaps an existing period.']);
            }

            return FinancePeriod::create([
                'business_id' => $business->id, 'period_from' => $from, 'period_to' => $to,
                'created_by' => $actor, 'status' => 'open',
            ]);
        }, 3);
    }

    private function snapshot(Business $business, string $through): array
    {
        $totals = ['debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero()];
        foreach (FinanceJournalLine::query()->where('finance_journal_lines.business_id', $business->id)
            ->join('finance_journals as j', function ($j) {
                $j->on('j.id', '=', 'finance_journal_lines.journal_id')
                    ->on('j.business_id', '=', 'finance_journal_lines.business_id');
            })->whereDate('j.posted_on', '<=', $through)
            ->get(['finance_journal_lines.base_debit', 'finance_journal_lines.base_credit']) as $line) {
            $totals['debit'] = $totals['debit']->plus((string) $line->base_debit);
            $totals['credit'] = $totals['credit']->plus((string) $line->base_credit);
        }
        if (! $totals['debit']->isEqualTo($totals['credit'])) {
            throw ValidationException::withMessages(['period' => 'The trial balance does not balance in the company base currency.']);
        }

        return [
            'through' => $through, 'business_id' => $business->id,
            'journal_count' => FinanceJournal::query()->where('business_id', $business->id)
                ->whereDate('posted_on', '<=', $through)->count(),
            'total_base_debits' => (string) $totals['debit']->toScale(2),
            'total_base_credits' => (string) $totals['credit']->toScale(2),
            'account_count' => FinanceAccount::query()
                ->where('business_id', $business->id)->count(),
        ];
    }

    private function outstanding(Business $business, string $through): void
    {
        $b = $business->id;
        $reconciliation = app(FinanceManagementReports::class)->reconciliation($business, $through);
        if (! $reconciliation['matches']) {
            $first = $reconciliation['issues'][0];
            throw ValidationException::withMessages(['period' => $first['reference'].': '.$first['note'].' Review the dated financial report before closing.']);
        }
        $sources = [
            [FinanceWorkflow::class, 'posting_status', 'not_posted', 'event_on'],
            [StockAdjustment::class, 'posting_status', 'not_posted', 'event_on'],
            [FinanceOperation::class, 'posting_status', 'not_posted', 'event_on'],
            [FinanceRegisterEntry::class, 'posting_status', 'not_posted', 'event_on'],
            [CommercialInvoice::class, 'posting_status', 'not_posted', 'issued_at'],
            [FinanceSupplierBill::class, 'posting_status', 'not_posted', 'vendor_invoice_date'],
            [FinancePayment::class, 'status', 'pending', 'payment_on'],
            [FinanceShipmentCost::class, 'status', 'pending', 'paid_on'],
            [FinanceCreditNote::class, 'posting_status', 'not_posted', 'created_at'],
            [FinanceInventoryRelief::class, 'posting_status', 'not_posted', 'created_at'],
            [FinancePayrollRun::class, 'posting_status', 'not_posted', 'period_to'],
            [FinancePayrollSettlement::class, 'status', 'pending', 'paid_on'],
            [FinancePayrollReversal::class, 'posting_status', 'not_posted', 'created_at'],
            [FinanceTransactionReversal::class, 'posting_status', 'not_posted', 'created_at'],
            [FinancePartialBankReturn::class, 'posting_status', 'not_posted', 'created_at'],
            [FinanceMonetaryRevaluation::class, 'posting_status', 'not_posted', 'valued_on'],
            [FinanceForeignBankOpening::class, 'posting_status', 'not_posted', 'effective_on'],
            [FinanceFxRevaluation::class, 'posting_status', 'not_posted', 'valued_on'],
            [FinanceBalanceAdjustment::class, 'posting_status', 'not_posted', 'created_at'],
            [FinanceOpeningBalance::class, 'posting_status', 'not_posted', 'effective_on'],
        ];
        foreach ($sources as [$class,$column,$status,$date]) {
            if ($class::query()->where('business_id', $b)
                ->where($column, $status)->whereDate($date, '<=', $through)->exists()) {
                throw ValidationException::withMessages(['period' => 'There are unposted '.strtolower(class_basename($class)).
                    ' records on or before this closing date. Complete or correct them first.']);
            }
        }
        if (CashClose::query()->where('business_id', $b)->whereIn('status', ['pending', 'variance_pending'])->whereDate('counted_on', '<=', $through)->exists()) {
            throw ValidationException::withMessages(['period' => 'Complete pending cash counts and variances before closing.']);
        }
        $unbilled = DB::table('goods_receipts as gr')->where('gr.business_id', $b)->whereDate('gr.received_on', '<=', $through)->groupBy('gr.purchase_order_id')->havingRaw('ROUND(SUM(gr.accepted_quantity),3) > ROUND((SELECT COALESCE(SUM(bill.accepted_quantity),0) FROM finance_supplier_bills bill WHERE bill.business_id = ? AND bill.purchase_order_id = gr.purchase_order_id AND bill.posting_status = ? AND bill.vendor_invoice_date <= ?),3)', [$b, 'posted', $through])->exists();
        if ($unbilled) {
            throw ValidationException::withMessages(['period' => 'Accepted produce on or before this close date is not fully covered by posted supplier bills. Resolve the receipt cost before closing.']);
        }
        if (FinancePostingRequest::query()->where('business_id', $b)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('posting_on', '<=', $through)->exists()) {
            throw ValidationException::withMessages(['period' => 'Finish the pending financial posting approvals before closing this period.']);
        }
        if (FinanceBankStatement::query()->where('business_id', $b)
            ->where('status', 'open')->whereDate('period_to', '<=', $through)->exists()) {
            throw ValidationException::withMessages(['period' => 'Reconcile imported bank statements ending on or before this closing date.']);
        }
        // New named, single-currency bank ledgers additionally need independent
        // book-balance proof. Legacy shared-currency bank data still requires
        // historical reconstruction and cannot be silently declared verified.
        $namedAccounts = FinanceBankProfile::query()->where('business_id', $b)
            ->pluck('account_id')->all();
        if ($namedAccounts !== []) {
            foreach (FinanceBankStatement::query()->where('business_id', $b)
                ->whereIn('account_id', $namedAccounts)
                ->whereDate('period_to', '<=', $through)->get() as $statement) {
                $check = FinanceBankLedgerCheck::query()->where('business_id', $b)
                    ->where('statement_id', $statement->id)->first();
                if (! $check || (! app(FinanceBankLedgerProofs::class)->isCurrent($check) && ! app(FinanceBankLedgerProofs::class)->isSuperseded($check))) {
                    throw ValidationException::withMessages(['period' => 'A named-bank statement is not yet independently verified against its ledger, or its evidence became stale. Complete bank-to-ledger proof before closing the period.']);
                }
            }
        }
    }

    private function assertLatestClosure(FinancePeriod $period): void
    {
        if (FinancePeriod::query()->where('business_id', $period->business_id)
            ->where('id', '!=', $period->id)
            ->whereDate('period_from', '>', $period->period_to->toDateString())
            ->whereIn('status', ['pending', 'closed'])->exists()) {
            throw ValidationException::withMessages(['period' => 'Reopen later closed or closing periods first, from the most recent backwards. A past close cannot be amended while later periods remain locked.']);
        }
    }

    public function requestReopen(FinancePeriod $period, int $actor, array $data): FinancePeriodReopenRequest
    {
        return DB::transaction(function () use ($period, $actor, $data) {
            $business = Business::query()->whereKey($period->business_id)
                ->lockForUpdate()->firstOrFail();
            $period = FinancePeriod::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($period->id);
            abort_unless($period->status === 'closed' && $period->closed_at
                && $period->close_hash && is_array($period->close_snapshot), 422,
                'Only a formally closed financial period with its original close evidence may be reopened.');
            $this->assertLatestClosure($period);
            if (FinancePeriodReopenRequest::query()->where('business_id', $business->id)
                ->where('period_id', $period->id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['period' => 'A separate reviewer has already been asked to consider reopening this period.']);
            }
            $sourceHash = hash('sha256', json_encode($period->close_snapshot, JSON_THROW_ON_ERROR));
            abort_unless(hash_equals($period->close_hash, $sourceHash), 422,
                'The original closed financial evidence changed unexpectedly.');
            $case = FinancePeriodReopenRequest::create([
                'business_id' => $business->id, 'period_id' => $period->id,
                'status' => 'pending', 'reason' => $data['reason'],
                'materiality_assessment' => $data['materiality_assessment'],
                'external_evidence' => $data['external_evidence'],
                'original_close_snapshot' => $period->close_snapshot,
                'original_close_hash' => $period->close_hash,
                'original_closed_at' => $period->closed_at,
                'requested_by' => $actor,
            ]);
            $this->event($period, $actor, 'reopening_requested',
                $data['reason'].'; materiality: '.$data['materiality_assessment'], [
                    'reopen_request_id' => $case->id,
                    'original_close_hash' => $period->close_hash,
                    'original_closed_at' => $period->closed_at->toIso8601String(),
                    'external_evidence' => $data['external_evidence'],
                ]);

            return $case;
        }, 3);
    }

    public function reviewReopen(FinancePeriodReopenRequest $case, bool $approve,
        int $actor, string $notes): FinancePeriodReopenRequest
    {
        return DB::transaction(function () use ($case, $approve, $actor, $notes) {
            $business = Business::query()->whereKey($case->business_id)
                ->lockForUpdate()->firstOrFail();
            $case = FinancePeriodReopenRequest::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($case->id);
            abort_unless($case->status === 'pending', 422,
                'This reopening request is no longer pending.');
            abort_unless($case->requested_by !== $actor, 422,
                'An independent authorised owner or administrator must approve a period reopening.');
            $period = FinancePeriod::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($case->period_id);
            abort_unless($period->status === 'closed'
                && $period->close_hash === $case->original_close_hash
                && $period->closed_at?->equalTo($case->original_closed_at)
                && $period->close_snapshot == $case->original_close_snapshot
                && hash('sha256', json_encode($case->original_close_snapshot, JSON_THROW_ON_ERROR))
                    === $case->original_close_hash, 422,
                'The frozen original closed financial evidence no longer agrees with this request.');
            if ($approve) {
                $this->assertLatestClosure($period);
            }
            $case->update([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by' => $actor, 'review_notes' => $notes, 'reviewed_at' => now(),
            ]);
            if ($approve) {
                // Preserve the original close snapshot and its reviewer in
                // immutable request/event history. The period can only close
                // again through the normal independently reviewed workflow.
                $period->update([
                    'status' => 'open', 'requested_by' => null,
                    'reviewed_by' => null, 'closed_at' => null,
                ]);
            }
            $this->event($period, $actor, $approve ? 'reopened' : 'reopening_rejected',
                $notes, [
                    'reopen_request_id' => $case->id,
                    'original_close_hash' => $case->original_close_hash,
                    'original_closed_at' => $case->original_closed_at->toIso8601String(),
                    'reviewed_by' => $actor,
                    'approved' => $approve,
                ]);

            return $case;
        }, 3);
    }

    private function event(FinancePeriod $period, int $actor, string $action,
        string $reason, ?array $snapshot = null): void
    {
        FinancePeriodEvent::create([
            'business_id' => $period->business_id, 'period_id' => $period->id,
            'actor_id' => $actor, 'action' => $action, 'reason' => $reason,
            'snapshot' => $snapshot, 'created_at' => now(),
        ]);
    }

    public function submit(FinancePeriod $period, int $actor, string $reason): FinancePeriod
    {
        return DB::transaction(function () use ($period, $actor, $reason) {
            $business = Business::query()->whereKey($period->business_id)
                ->lockForUpdate()->firstOrFail();
            $period = FinancePeriod::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($period->id);
            abort_unless($period->status === 'open', 422, 'Only open periods may be submitted for closing.');
            abort_unless(! FinancePeriod::query()->where('business_id', $business->id)
                ->where('id', '!=', $period->id)->where('status', 'open')
                ->whereDate('period_to', '<', $period->period_from->toDateString())->exists(), 422,
                'Close earlier open financial periods first.');
            $this->outstanding($business, $period->period_to->toDateString());
            $snapshot = $this->snapshot($business, $period->period_to->toDateString());
            $period->update([
                'status' => 'pending', 'requested_by' => $actor,
                'reason' => $reason, 'close_snapshot' => $snapshot,
                'close_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            ]);
            $this->event($period, $actor, 'submitted', $reason, $snapshot);

            return $period;
        }, 3);
    }

    public function review(FinancePeriod $period, bool $approve, int $actor, string $reason): FinancePeriod
    {
        return DB::transaction(function () use ($period, $approve, $actor, $reason) {
            $business = Business::query()->whereKey($period->business_id)
                ->lockForUpdate()->firstOrFail();
            $period = FinancePeriod::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($period->id);
            abort_unless($period->status === 'pending', 422, 'The close request is no longer pending.');
            abort_unless($period->requested_by !== $actor, 422,
                'An independent owner or administrator must review the period closure.');
            if ($approve) {
                $this->outstanding($business, $period->period_to->toDateString());
                $snapshot = $this->snapshot($business, $period->period_to->toDateString());
                abort_unless(hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))
                    === $period->close_hash, 422,
                    'Journal balances or chart details changed after the closing request.');
                $period->update(['status' => 'closed', 'reviewed_by' => $actor, 'closed_at' => now()]);
            } else {
                $period->update([
                    'status' => 'open', 'requested_by' => null,
                    'close_snapshot' => null, 'close_hash' => null, 'closed_at' => null,
                    'reviewed_by' => $actor,
                ]);
            }
            $this->event($period, $actor, $approve ? 'closed' : 'rejected', $reason,
                $approve ? $period->close_snapshot : null);

            return $period;
        }, 3);
    }
}
