<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankStatementLine;
use App\Models\FinanceJournal;
use App\Models\FinanceMoneyCorrection;
use App\Models\FinanceOperation;
use App\Models\FinancePartialBankReturn;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceTransactionReversal;
use App\Models\FinanceWorkflow;
use App\Models\MarketAccountability;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class FinanceMoneyCorrections
{
    public function corrected(FinanceOperation $source, bool $pending = false, ?int $except = null, string $field = 'amount'): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach (FinanceMoneyCorrection::query()->where('business_id', $source->business_id)->where('operation_id', $source->id)->when($except, fn ($q) => $q->where('workflow_id', '!=', $except))->whereHas('workflow', fn ($q) => $q->whereIn('posting_status', $pending ? ['not_posted', 'posted'] : ['posted']))->pluck($field) as $value) {
            $sum = $sum->plus((string) $value);
        }

        return $sum;
    }

    public function remaining(FinanceOperation $source, bool $pending = true, ?int $except = null): BigDecimal
    {
        $available = BigDecimal::of((string) $source->amount)->minus($this->corrected($source, $pending, $except));
        foreach (FinanceOperation::query()->where('business_id', $source->business_id)->where('parent_id', $source->id)->whereIn('posting_status', ['not_posted', 'posted'])->get() as $child) {
            $available = $available->minus((string) $child->amount)->plus($this->corrected($child));
        }

        $available = $available->minus(app(FinanceOpeningItems::class)->usedByTarget($source->business_id, 'expense_id', $source->id, $pending)['amount']);

        return $available;
    }

    private function portion(string $value, BigDecimal $part, BigDecimal $total): BigDecimal
    {
        return $part->isEqualTo($total) ? BigDecimal::of($value) : app(FinanceMath::class)->money(BigDecimal::of($value)->multipliedBy($part)->dividedBy($total, 14, RoundingMode::HalfUp));
    }

    private function bankSnapshot(FinanceBankStatementLine $line): array
    {
        return ['line_id' => $line->id, 'statement_id' => $line->statement_id, 'account_id' => $line->statement->account_id, 'currency' => $line->statement->currency, 'file_sha256' => $line->statement->file_sha256, 'transaction_on' => $line->transaction_on->toDateString(), 'direction' => $line->direction, 'amount' => (string) $line->amount, 'bank_reference' => $line->bank_reference, 'description' => $line->description];
    }

    public function bankReservation(int $businessId, int $lineId): ?object
    {
        return DB::table('finance_workflow_bank_evidence as e')->join('finance_workflows as w', function ($q) {
            $q->on('w.id', '=', 'e.workflow_id')->on('w.business_id', '=', 'e.business_id');
        })->where('e.business_id', $businessId)->where('e.statement_line_id', $lineId)->whereIn('w.posting_status', ['not_posted', 'posted'])->first(['e.*']);
    }

    public function bindBank(FinanceWorkflow $workflow, int $legId, int $lineId): void
    {
        $leg = $workflow->legs()->whereKey($legId)->firstOrFail();
        $line = FinanceBankStatementLine::query()->where('business_id', $workflow->business_id)->with('statement')->lockForUpdate()->findOrFail($lineId);
        abort_unless($line->statement->status === 'open' && $line->statement->account_id === $leg->cash_account_id && $line->statement->currency === $leg->currency && $line->transaction_on->toDateString() === $workflow->event_on->toDateString() && $line->direction === ($leg->cash_direction === 'in' ? 'credit' : 'debit') && BigDecimal::of((string) $line->amount)->isEqualTo((string) $leg->amount), 422, 'The actual returned bank line must match this exact date, account, currency, direction and amount.');
        abort_if($this->bankReservation($workflow->business_id, $lineId) || FinanceBankMatch::query()->where('business_id', $workflow->business_id)->where('statement_line_id', $lineId)->where('status', '!=', 'rejected')->exists(), 422, 'This bank return is already matched or reserved.');
        foreach ([FinanceRegisterEntry::class, FinancePartialBankReturn::class, FinanceTransactionReversal::class] as $model) {
            abort_if($model::query()->where('business_id', $workflow->business_id)->where('return_bank_line_id', $lineId)->exists(), 422, 'This bank return is reserved by another correction.');
        }
        DB::table('finance_workflow_bank_evidence')->insert(['business_id' => $workflow->business_id, 'workflow_id' => $workflow->id, 'leg_id' => $legId, 'statement_line_id' => $lineId, 'data_snapshot' => json_encode($this->bankSnapshot($line), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function validateBank(FinanceWorkflow $workflow): void
    {
        foreach (DB::table('finance_workflow_bank_evidence')->where('business_id', $workflow->business_id)->where('workflow_id', $workflow->id)->get() as $evidence) {
            $line = FinanceBankStatementLine::query()->where('business_id', $workflow->business_id)->with('statement')->lockForUpdate()->findOrFail($evidence->statement_line_id);
            abort_unless($line->statement->status === 'open' && $this->bankSnapshot($line) === json_decode($evidence->data_snapshot, true, 512, JSON_THROW_ON_ERROR), 422, 'Bank-return evidence changed after preparation.');
            abort_if(FinanceBankMatch::query()->where('business_id', $workflow->business_id)->where('statement_line_id', $line->id)->where('status', '!=', 'rejected')->exists(), 422, 'The reserved bank return was matched before correction posting.');
        }
    }

    public function validate(FinanceWorkflow $workflow): void
    {
        $correction = FinanceMoneyCorrection::query()->where('business_id', $workflow->business_id)->where('workflow_id', $workflow->id)->firstOrFail();
        $source = FinanceOperation::query()->where('business_id', $workflow->business_id)->findOrFail($correction->operation_id);
        app(FinanceProductionCosts::class)->assertUnallocated($workflow->business_id, 'expense', $source->id);
        abort_unless($source->posting_status === 'posted' && ! BigDecimal::of((string) $correction->amount)->isGreaterThan($this->remaining($source, true, $workflow->id)), 422, 'The source was consumed, corrected or changed. Withdraw this correction and prepare it again.');
        abort_unless($correction->data_snapshot === $workflow->data_snapshot['details']['correction'], 422, 'The correction source evidence changed.');
        $this->validateBank($workflow);
    }

    public function prepare(Business $business, FinanceOperation $source, array $data, int $actor): FinanceWorkflow
    {
        return DB::transaction(function () use ($business, $source, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $flows = app(FinanceWorkflows::class);
            if ($old = $flows->existing($business, 'money_correction', $data)) {
                return $old;
            }$source = FinanceOperation::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($source->id);
            if ($source->market_trip_id) {
                abort_if(MarketAccountability::query()->where('business_id', $business->id)->where('market_trip_id', $source->market_trip_id)->where('status', 'closed')->exists(), 422, 'Reopen the market accountability before correcting its financial records.');
            }
            app(FinanceProductionCosts::class)->assertUnallocated($business->id, 'expense', $source->id);
            abort_unless($source->posting_status === 'posted', 422, 'Choose a posted money source to correct.');
            $amount = app(FinanceMath::class)->money($data['amount']);
            abort_unless($amount->isGreaterThan(0) && ! $amount->isGreaterThan($this->remaining($source)), 422, 'The correction exceeds the unconsumed and uncorrected original amount.');
            abort_unless($data['event_on'] >= $source->event_on->toDateString(), 422, 'A return cannot precede its original event.');
            $journal = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', 'money_operation')->where('source_id', $source->id)->with('lines.account')->firstOrFail();
            $original = BigDecimal::of((string) $source->amount);
            $used = $this->corrected($source);
            $total = $used->plus($amount);
            $lines = [];
            $baseDr = BigDecimal::zero();
            $baseCr = BigDecimal::zero();
            $controlBase = BigDecimal::zero();
            $control = match ($source->kind) {
                'customer_deposit','customer_apply','customer_refund' => 'customer_deposits','supplier_advance','supplier_apply','supplier_return' => 'supplier_advances','staff_advance','staff_apply','staff_expense','staff_return' => 'staff_advances','expense','expense_payment' => 'expense_payable',default => null
            };
            foreach ($journal->lines as $row) {
                $values = [];
                foreach (['debit', 'credit', 'base_debit', 'base_credit'] as $field) {
                    $values[$field] = $this->portion((string) $row->{$field}, $total, $original)->minus($this->portion((string) $row->{$field}, $used, $original));
                }if (collect($values)->every(fn ($v) => $v->isZero())) {
                    continue;
                }$line = app(FinanceLedger::class)->line($row->account->system_key, (string) $values['credit'], (string) $values['debit'], (string) $values['base_credit'], (string) $values['base_debit'], 'Documented correction of '.$source->reference);
                $lines[] = $line;
                $baseDr = $baseDr->plus($values['base_credit']);
                $baseCr = $baseCr->plus($values['base_debit']);
                if ($row->account->system_key === $control) {
                    $controlBase = $values['base_debit']->plus($values['base_credit']);
                }
            }
            $difference = $baseDr->minus($baseCr);
            if (! $difference->isZero()) {
                $lines[] = app(FinanceLedger::class)->line($difference->isGreaterThan(0) ? 'fx_gain' : 'fx_loss', '0', '0', $difference->isLessThan(0) ? (string) $difference->abs() : '0', $difference->isGreaterThan(0) ? (string) $difference : '0', 'Rounding on proportional source correction');
            }
            $cash = null;
            $direction = null;
            if (in_array($source->kind, FinanceOperations::CASH, true)) {
                $cash = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($source->cash_account_id);
                $originalIncoming = in_array($source->kind, ['customer_deposit', 'supplier_return', 'staff_return'], true);
                $direction = $originalIncoming ? 'out' : 'in';
                if ($cash->system_key === 'cash' || str_starts_with($cash->system_key, 'cash_')) {
                    abort_unless(mb_strlen(trim($data['cash_witness'] ?? '')) >= 10, 422, 'Record who witnessed the actual cash return and its signed voucher.');
                }
            }
            $guards = [['type' => 'operation', 'id' => $source->id, 'snapshot_hash' => $source->snapshot_hash]];
            if ($cash && $direction === 'out' && ($cash->system_key === 'cash' || str_starts_with($cash->system_key, 'cash_'))) {
                $basis = $flows->accountBasis($business->id, $cash->id, $data['event_on']);
                abort_unless(! $basis['units']->isLessThan($amount), 422, 'The actual cash return exceeds the posted cash held.');
                $guards[] = ['type' => 'account', 'id' => $cash->id, 'fingerprint' => $basis['fingerprint']];
            }
            $details = ['operation_id' => $source->id, 'original_journal_id' => $journal->id, 'amount' => (string) $amount, 'control_base' => (string) $controlBase, 'source_snapshot_hash' => $source->snapshot_hash, 'cash_witness' => $data['cash_witness'] ?? null];
            $leg = ['currency' => $source->currency, 'exchange_rate_id' => $source->exchange_rate_id, 'rate_to_base' => (string) $journal->rate_to_base, 'amount' => (string) $amount, 'cash_account_id' => $cash?->id, 'cash_direction' => $direction, 'lines' => $lines];
            $legs = [$leg];
            $otherCash = null;
            if ($source->kind === 'transfer') {
                $otherCash = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($source->destination_account_id);
                $returned = $flows->accountBasis($business->id, $otherCash->id, $data['event_on']);
                abort_unless(! $returned['units']->isLessThan($amount), 422, 'The receiving account no longer holds enough money for this actual transfer return.');
                $guards[] = ['type' => 'account', 'id' => $otherCash->id, 'fingerprint' => $returned['fingerprint']];
                $sourceLine = collect($lines)->firstWhere('account', $cash->system_key);
                $base = $sourceLine['base_debit'];
                $l = app(FinanceLedger::class);
                $legs[0]['lines'] = [$l->line($cash->system_key, (string) $amount, '0', $base, '0', 'Actual transfer returned to its original source'), $l->line('fx_clearing', '0', (string) $amount, '0', $base, 'Transfer return clearing')];
                $legs[] = [...$leg, 'cash_account_id' => $otherCash->id, 'cash_direction' => 'out', 'lines' => [$l->line('fx_clearing', (string) $amount, '0', $base, '0', 'Transfer return clearing'), $l->line($otherCash->system_key, '0', (string) $amount, '0', $base, 'Actual transfer returned from its original destination')]];
                if ($otherCash->system_key === 'cash' || str_starts_with($otherCash->system_key, 'cash_')) {
                    abort_unless(mb_strlen(trim($data['cash_witness'] ?? '')) >= 10, 422, 'Record the custodian and signed cash-transfer return voucher.');
                }
            }
            $flow = $flows->create($business, 'money_correction', $data, $actor, $legs, ['correction' => $details], $guards);
            FinanceMoneyCorrection::create(['business_id' => $business->id, 'operation_id' => $source->id, 'workflow_id' => $flow->id, 'original_journal_id' => $journal->id, 'amount' => (string) $amount, 'control_base' => (string) $controlBase, 'data_snapshot' => $details]);
            if ($cash && ! ($cash->system_key === 'cash' || str_starts_with($cash->system_key, 'cash_'))) {
                abort_unless(! empty($data['return_bank_line_id']), 422, 'Import the actual opposite bank return before preparing this correction.');
                $this->bindBank($flow, $flow->legs()->firstOrFail()->id, (int) $data['return_bank_line_id']);
            }
            if ($otherCash && ! ($otherCash->system_key === 'cash' || str_starts_with($otherCash->system_key, 'cash_'))) {
                abort_unless(! empty($data['other_bank_line_id']), 422, 'Import the actual opposite bank movement from the original transfer destination.');
                $this->bindBank($flow, $flow->legs()->get()->last()->id, (int) $data['other_bank_line_id']);
            }

            return $flow;
        }, 3);
    }
}
