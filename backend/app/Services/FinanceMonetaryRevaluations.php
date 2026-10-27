<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceCreditNote;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceJournal;
use App\Models\FinanceMonetaryRevaluation;
use App\Models\FinanceMoneyCorrection;
use App\Models\FinanceOperation;
use App\Models\FinancePartialBankReturn;
use App\Models\FinancePayment;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceTransactionReversal;
use App\Models\FinanceWorkflowLeg;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceMonetaryRevaluations
{
    private function document(Business $business, string $type, int $id): CommercialInvoice|FinanceSupplierBill
    {
        abort_unless(in_array($type, ['commercial_invoice', 'supplier_bill'], true), 404);

        return ($type === 'commercial_invoice' ? CommercialInvoice::class : FinanceSupplierBill::class)::query()->where('business_id', $business->id)->findOrFail($id);
    }

    public function position(Business $business, string $type, int $id, ?string $through = null, bool $allowBase = false): array
    {
        $document = $this->document($business, $type, $id);
        abort_unless($document->posting_status === 'posted'
            && ($allowBase || $document->currency !== $business->base_currency), 422,
            'Only posted, outstanding foreign-currency invoices and supplier bills can be revalued.');
        $relation = $type === 'commercial_invoice' ? 'commercial_invoice_id' : 'supplier_bill_id';
        $account = $type === 'commercial_invoice' ? 'receivable' : 'payable';
        $direction = $account === 'receivable' ? 1 : -1;
        $sourceIds = [[$type, $document->id]];
        $payments = FinancePayment::query()->where('business_id', $business->id)
            ->where($relation, $document->id)
            ->whereIn('status', ['posted', 'reversed'])->orderBy('id')->get();
        foreach ($payments as $payment) {
            $sourceIds[] = ['payment', $payment->id];
            $reversal = FinanceTransactionReversal::query()->where('business_id', $business->id)
                ->where('source_type', 'payment')->where('source_id', $payment->id)
                ->where('posting_status', 'posted')->first();
            if ($reversal) {
                $sourceIds[] = ['transaction_reversal', $reversal->id];
            }
            foreach (FinancePartialBankReturn::query()->where('business_id', $business->id)
                ->where('source_type', 'payment')->where('source_id', $payment->id)
                ->where('posting_status', 'posted')->orderBy('id')->get() as $partial) {
                $sourceIds[] = ['partial_bank_return', $partial->id];
            }
        }
        $adjustment = FinanceBalanceAdjustment::query()->where('business_id', $business->id)
            ->where($relation, $document->id)->where('posting_status', 'posted')->first();
        if ($adjustment) {
            $sourceIds[] = ['balance_adjustment', $adjustment->id];
        }
        foreach (FinanceMonetaryRevaluation::query()->where('business_id', $business->id)
            ->where('source_type', $type)->where('source_id', $id)
            ->where('posting_status', 'posted')->orderBy('id')->get() as $prior) {
            $sourceIds[] = ['monetary_revaluation', $prior->id];
        }
        foreach (FinanceOperation::query()->where('business_id', $business->id)->where($relation, $id)->where('posting_status', 'posted')->pluck('id') as $operationId) {
            $sourceIds[] = ['money_operation', $operationId];
            foreach (FinanceMoneyCorrection::query()->where('business_id', $business->id)->where('operation_id', $operationId)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'posted'))->get() as $correction) {
                foreach (FinanceWorkflowLeg::query()->where('business_id', $business->id)->where('workflow_id', $correction->workflow_id)->pluck('id') as $legId) {
                    $sourceIds[] = ['workflow_leg', $legId];
                }
            }
        }
        foreach (['finance_trade_credits', 'finance_trade_credit_uses', 'finance_opening_item_uses'] as $table) {
            foreach (DB::table($table.' as t')->join('finance_workflows as w', 'w.id', '=', 't.workflow_id')->where('t.business_id', $business->id)->where('w.business_id', $business->id)->where('t.'.$relation, $id)->where('w.posting_status', 'posted')->get(['w.id']) as $flow) {
                foreach (FinanceWorkflowLeg::query()->where('business_id', $business->id)->where('workflow_id', $flow->id)->where('currency', $document->currency)->pluck('id') as $legId) {
                    $sourceIds[] = ['workflow_leg', $legId];
                }
            }
        }
        foreach (DB::table('finance_workflow_corrections as fc')->join('finance_workflows as cw', 'cw.id', '=', 'fc.workflow_id')->where('fc.business_id', $business->id)->where('cw.business_id', $business->id)->where('cw.posting_status', 'posted')->get() as $correction) {
            $originalLegs = FinanceWorkflowLeg::query()->where('business_id', $business->id)->where('workflow_id', $correction->original_workflow_id)->pluck('id')->all();
            if (collect($sourceIds)->contains(fn ($pair) => $pair[0] === 'workflow_leg' && in_array($pair[1], $originalLegs, true))) {
                foreach (FinanceWorkflowLeg::query()->where('business_id', $business->id)->where('workflow_id', $correction->workflow_id)->where('currency', $document->currency)->pluck('id') as $legId) {
                    $sourceIds[] = ['workflow_leg', $legId];
                }
            }
        }
        if ($type === 'commercial_invoice') {
            foreach (FinanceCreditNote::query()->where('business_id', $business->id)->where('commercial_invoice_id', $id)->where('posting_status', 'posted')->pluck('id') as $creditId) {
                $sourceIds[] = ['credit_note', $creditId];
            }
        }
        $includedSources = [];
        $book = BigDecimal::zero();
        $units = BigDecimal::zero();
        $unrealized = BigDecimal::zero();
        $fingerprints = [];
        $latest = '0000-00-00';
        foreach ($sourceIds as [$journalType,$sourceId]) {
            $journal = FinanceJournal::query()->where('business_id', $business->id)
                ->where('source_type', $journalType)->where('source_id', $sourceId)
                ->with('lines.account')->firstOrFail();
            abort_unless($journal->currency === $document->currency, 422,
                'A related journal has a different document currency.');
            $day = $journal->posted_on->toDateString();
            if ($through && $day > $through) {
                continue;
            }
            $includedSources[] = [$journalType, $sourceId];
            if ($day > $latest) {
                $latest = $day;
            }
            foreach ($journal->lines->sortBy('id') as $line) {
                abort_unless($line->business_id === $business->id
                    && $line->account?->business_id === $business->id, 422);
                $key = $line->account->system_key;
                if ($key === $account) {
                    $units = $units->plus(BigDecimal::of((string) $line->debit)
                        ->minus((string) $line->credit)->multipliedBy($direction));
                    $book = $book->plus(BigDecimal::of((string) $line->base_debit)
                        ->minus((string) $line->base_credit)->multipliedBy($direction));
                }
                // The net unrealised adjustment still open for this document.
                // Reclassification during settlement is recorded in the same
                // payment journal, so the outstanding balance is exact.
                if ($key === 'unrealized_fx_gain') {
                    $unrealized = $unrealized->plus((string) $line->base_credit)
                        ->minus((string) $line->base_debit);
                }
                if ($key === 'unrealized_fx_loss') {
                    $unrealized = $unrealized->minus((string) $line->base_debit)
                        ->plus((string) $line->base_credit);
                }
                $fingerprints[] = [
                    'journal_id' => $journal->id, 'line_id' => $line->id,
                    'date' => $day, 'account_id' => $line->account_id,
                    'debit' => (string) $line->debit, 'credit' => (string) $line->credit,
                    'base_debit' => (string) $line->base_debit,
                    'base_credit' => (string) $line->base_credit,
                ];
            }
        }
        foreach ($includedSources as [$journalType,$sourceId]) {
            foreach (DB::table('finance_cash_settlements')->where('business_id', $business->id)->where('source_type', $journalType)->where('source_id', $sourceId)->pluck('unrealized_released') as $released) {
                $unrealized = $unrealized->plus((string) $released);
            }
        }
        $outstanding = app(FinanceDocuments::class)->outstanding($document, false);
        if (! $through && ! $outstanding->isEqualTo($units)) {
            throw ValidationException::withMessages(['document' => 'The document currency balance does not agree with allocated posted journals. Investigate prior corrections before revaluing or settling it.']);
        }

        return [
            'source_type' => $type, 'source_id' => $id,
            'source_reference' => $document->reference, 'currency' => $document->currency,
            'base_currency' => $business->base_currency, 'account_key' => $account,
            'foreign_balance' => (string) $units->toScale(2),
            'previous_base' => (string) $book->toScale(2),
            'unrealized_open' => (string) $unrealized->toScale(2),
            'latest_journal_on' => $latest,
            'journal_fingerprint' => hash('sha256',
                json_encode($fingerprints, JSON_THROW_ON_ERROR)),
        ];
    }

    public function prepare(Business $business, array $data, int $actor): FinanceMonetaryRevaluation
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $type = $data['source_type'];
            $id = (int) $data['source_id'];
            $date = $data['valued_on'];
            app(FinancePeriodControls::class)->assertWritable($business, $date);
            if (FinanceMonetaryRevaluation::query()->where('business_id', $business->id)
                ->where('source_type', $type)->where('source_id', $id)
                ->whereDate('valued_on', $date)->exists()) {
                throw ValidationException::withMessages(['valued_on' => 'This document already has a foreign-currency revaluation on the selected date.']);
            }
            $snapshot = $this->basis($business, $type, $id, (int) $data['exchange_rate_id'],
                $date, $data['valuation_evidence']);

            return FinanceMonetaryRevaluation::create([
                'business_id' => $business->id,
                'reference' => 'FXDOC-'.Str::upper((string) Str::ulid()),
                'source_type' => $type, 'source_id' => $id,
                'currency' => $snapshot['currency'], 'valued_on' => $date,
                'exchange_rate_id' => $snapshot['exchange_rate_id'],
                'foreign_balance' => $snapshot['foreign_balance'],
                'previous_base' => $snapshot['previous_base'],
                'revalued_base' => $snapshot['revalued_base'],
                'change_base' => $snapshot['change_base'],
                'rate_to_base' => $snapshot['rate_to_base'],
                'valuation_evidence' => $data['valuation_evidence'],
                'data_snapshot' => $snapshot,
                'sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'posting_status' => 'not_posted', 'created_by' => $actor,
            ]);
        }, 3);
    }

    private function basis(Business $business, string $type, int $id, int $rateId,
        string $date, string $evidence): array
    {
        $doc = $this->document($business, $type, $id);
        $relation = $type === 'commercial_invoice' ? 'commercial_invoice_id' : 'supplier_bill_id';
        app(FinanceTradeCredits::class)->assertNoPending($business->id, $relation, $id);
        if (FinancePayment::query()->where('business_id', $business->id)
            ->where($relation, $id)->where('status', 'pending')->exists()
            || FinancePartialBankReturn::query()->where('business_id', $business->id)
                ->where('source_type', 'payment')
                ->whereIn('source_id', FinancePayment::query()->where('business_id', $business->id)
                    ->where($relation, $id)->select('id'))
                ->where('posting_status', 'not_posted')->exists()
            || FinanceBalanceAdjustment::query()->where('business_id', $business->id)
                ->where($relation, $id)->where('posting_status', 'not_posted')->exists()) {
            throw ValidationException::withMessages(['source_id' => 'Complete any pending payments, price concessions or partial bank returns before valuing this outstanding document.']);
        }
        $position = $this->position($business, $type, $id);
        if ($position['latest_journal_on'] > $date) {
            throw ValidationException::withMessages(['valued_on' => 'A foreign document cannot be revalued before its most recently posted financial movement.']);
        }
        $balance = BigDecimal::of($position['foreign_balance']);
        if ($balance->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['source_id' => 'This document has no positive outstanding foreign-currency receivable or payable.']);
        }
        $fx = app(FinanceRates::class)->rate($business, $doc->currency, $rateId, $date);
        $rate = FinanceExchangeRate::query()->where('business_id', $business->id)
            ->where('status', 'approved')->findOrFail($rateId);
        $newBase = app(FinanceMath::class)->base($balance, $fx);
        $change = $newBase->minus($position['previous_base']);
        if ($change->isZero()) {
            throw ValidationException::withMessages(['exchange_rate_id' => 'The approved rate produces no base-currency difference against this document carrying value.']);
        }

        return array_merge($position, [
            'valued_on' => $date, 'exchange_rate_id' => $rate->id,
            'rate_to_base' => (string) $fx, 'rate_source_note' => $rate->source_note,
            'revalued_base' => (string) $newBase->toScale(2),
            'change_base' => (string) $change->toScale(2),
            'valuation_evidence' => $evidence,
        ]);
    }

    public function lines(FinanceMonetaryRevaluation $source): array
    {
        abort_unless($source->posting_status === 'not_posted', 422,
            'This document currency revaluation is already posted.');
        $business = Business::query()->findOrFail($source->business_id);
        $snap = $this->basis($business, $source->source_type, $source->source_id,
            $source->exchange_rate_id, $source->valued_on->toDateString(),
            $source->valuation_evidence);
        abort_unless($source->data_snapshot == $snap
            && hash('sha256', json_encode($snap, JSON_THROW_ON_ERROR)) === $source->sha256
            && $source->currency === $snap['currency']
            && BigDecimal::of((string) $source->foreign_balance)->isEqualTo($snap['foreign_balance'])
            && BigDecimal::of((string) $source->previous_base)->isEqualTo($snap['previous_base'])
            && BigDecimal::of((string) $source->revalued_base)->isEqualTo($snap['revalued_base'])
            && BigDecimal::of((string) $source->change_base)->isEqualTo($snap['change_base'])
            && BigDecimal::of((string) $source->rate_to_base)->isEqualTo($snap['rate_to_base']),
            422, 'The document ledger, outstanding balance or valuation evidence changed. Prepare a new reviewed valuation.');
        $delta = BigDecimal::of($snap['change_base']);
        $amount = (string) $delta->abs()->toScale(2);
        $ledger = app(FinanceLedger::class);
        $asset = $snap['account_key'] === 'receivable';
        if (($asset && $delta->isGreaterThan(0)) ||
            (! $asset && $delta->isLessThan(0))) {
            // Gain: asset increases or liability decreases.
            return [
                $ledger->line($snap['account_key'], '0', '0',
                    $amount, '0',
                    'Non-cash revaluation of document balance'),
                $ledger->line('unrealized_fx_gain', '0', '0', '0', $amount,
                    'Unrealised, non-cash gain on remaining foreign monetary item'),
            ];
        }

        return [
            $ledger->line($snap['account_key'], '0', '0',
                '0', $amount,
                'Non-cash revaluation of document balance'),
            $ledger->line('unrealized_fx_loss', '0', '0', $amount, '0',
                'Unrealised, non-cash loss on remaining foreign monetary item'),
        ];
    }
}
