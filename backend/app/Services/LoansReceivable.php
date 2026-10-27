<?php

namespace App\Services;

use App\Models\Business;
use App\Models\LoanFacility;
use App\Models\LoanInstalment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class LoansReceivable
{
    public const KINDS = ['loan_writeoff' => 'Record a reviewed loan balance write-off', 'loan_drawdown' => 'Record money lent to the borrower', 'loan_opening' => 'Record an existing loan owed to us', 'loan_interest' => 'Accrue agreed interest receivable', 'loan_fee_accrual' => 'Record an earned fee receivable', 'loan_capitalise_interest' => 'Capitalise agreed unpaid interest', 'loan_repayment' => 'Record money received from the borrower', 'loan_revaluation' => 'Revalue the outstanding foreign loan', 'register_partial_return' => 'Record a partial actual money return', 'register_reversal' => 'Correct the latest posted entry'];

    public const ACCOUNTS = ['principal' => 'loans_receivable', 'interest' => 'loan_interest_receivable', 'fees' => 'loan_fees_receivable'];

    public function state(LoanFacility $loan, ?array $position = null): array
    {
        $p = $position ?? app(FinanceRegisterPostings::class)->position($loan);
        $result = $p;
        $unreal = [];
        $drawn = BigDecimal::zero();
        foreach (self::ACCOUNTS as $component => $key) {
            $result[$component] = $p['balances'][$key]['units'] ?? BigDecimal::zero();
            $result[$component.'_base'] = $p['balances'][$key]['base'] ?? BigDecimal::zero();
            $unreal[$component] = BigDecimal::zero();
        }
        foreach ($p['entries'] as $entry) {
            foreach ($unreal as $component => $value) {
                $unreal[$component] = $value->plus($entry->data_snapshot['effects'][$component.'_unreal'] ?? '0');
            }
            if ($entry->posting_status === 'posted' && in_array($entry->kind, ['loan_opening', 'loan_drawdown', 'loan_capitalise_interest'], true)) {
                $drawn = $drawn->plus((string) $entry->amount);
            }
        }
        foreach ($p['entries']->where('kind', 'register_partial_return')->where('posting_status', 'posted') as $returned) {
            $original = $p['entries']->firstWhere('id', $returned->original_entry_id);
            if ($original?->kind === 'loan_drawdown' && $original->posting_status === 'posted') {
                $drawn = $drawn->minus((string) $returned->cash_amount);
            }
        }

        return [...$result, 'unreal' => $unreal, 'drawn' => $drawn, 'deferred_fees_base' => BigDecimal::zero()];
    }

    private function share(BigDecimal $value, BigDecimal $amount, BigDecimal $total): BigDecimal
    {
        return $amount->isEqualTo($total) ? $value : app(FinanceMath::class)->money($value->multipliedBy($amount)->dividedBy($total, 14, RoundingMode::HalfUp));
    }

    public function writeOff(Business $b, LoanFacility $loan, array $data, array $position): array
    {
        abort_unless($loan->status === 'active', 422, 'Choose an active loan agreement.');
        abort_if(LoanInstalment::query()->where('business_id', $b->id)->where('loan_id', $loan->id)->where('status', 'active')->exists(), 422, 'Cancel or replace active unpaid instalments before recording a waiver/write-off; the cash forecast must reflect the agreed outcome.');
        $state = app(LoanRegister::class)->state($loan, $position);
        $lend = $loan->loan_direction === 'lend';
        $math = app(FinanceMath::class);
        $l = app(FinanceLedger::class);
        $lines = [];
        $effects = [];
        $total = BigDecimal::zero();
        $baseTotal = BigDecimal::zero();
        $components = [];
        foreach (self::ACCOUNTS as $component => $assetKey) {
            $amount = $math->money($data[$component] ?? '0');
            abort_unless(! $amount->isLessThan(0) && ! $amount->isGreaterThan($state[$component]), 422, 'Write off only an existing, independently supported unpaid component.');
            $components[$component] = (string) $amount;
            if ($amount->isZero()) {
                continue;
            }
            $base = $this->share($state[$component.'_base'], $amount, $state[$component]);
            $total = $total->plus($amount);
            $baseTotal = $baseTotal->plus($base);
            $key = $lend ? $assetKey : ($component === 'principal' ? app(LoanRegister::class)->account($loan) : ($component === 'interest' ? 'loan_interest_payable' : 'loan_fees_payable'));
            $lines[] = $l->line($key, $lend ? '0' : (string) $amount, $lend ? (string) $amount : '0', $lend ? '0' : (string) $base, $lend ? (string) $base : '0', 'Explicit signed '.($lend ? 'uncollectible loan' : 'lender waiver').' component');
            $unreal = $this->share($state['unreal'][$component], $amount, $state[$component]);
            $effects[$component.'_unreal'] = (string) $unreal->negated();
            if (! $unreal->isZero()) {
                $value = (string) $unreal->abs();
                $gain = $unreal->isGreaterThan(0);
                $lines[] = $l->line($gain ? 'unrealized_fx_gain' : 'fx_loss', '0', '0', $value, '0', 'Release resolved loan FX');
                $lines[] = $l->line($gain ? 'fx_gain' : 'unrealized_fx_loss', '0', '0', '0', $value, 'Realise only the resolved foreign balance');
            }
        }
        abort_unless($total->isGreaterThan(0), 422, 'Enter positive signed-off components.');
        $lines[] = $l->line($lend ? 'loan_bad_debt' : 'loan_waiver_recovery', $lend ? (string) $total : '0', $lend ? '0' : (string) $total, $lend ? (string) $baseTotal : '0', $lend ? '0' : (string) $baseTotal, 'Reviewed loan write-off / creditor waiver; no invented money movement');
        $rateId = isset($data['exchange_rate_id']) ? (int) $data['exchange_rate_id'] : null;

        return ['currency' => $loan->currency, 'amount' => (string) $total, 'rate_to_base' => app(FinanceRates::class)->rate($b, $loan->currency, $rateId, $data['event_on']), 'exchange_rate_id' => $rateId, 'lines' => $lines, 'effects' => $effects, 'details' => [...$components, 'lending_suspension_before' => (bool) $loan->lending_suspended, 'resolution' => $lend ? 'uncollectible_lending' : 'signed_creditor_waiver']];
    }

    public function financial(Business $b, LoanFacility $loan, string $kind, array $data, array $position): array
    {
        abort_unless(isset(self::KINDS[$kind]) && $loan->status === 'active' && $loan->loan_direction === 'lend', 422, 'Choose an active loan the business has lent out.');
        $agreement = app(LoanAgreementAmendments::class)->terms($loan, $data['event_on']);
        $dated = clone $loan;
        $dated->forceFill($agreement);
        abort_unless($data['event_on'] >= $loan->starts_on->toDateString(), 422, 'The loan event cannot precede its agreement.');
        $state = $this->state($loan, $position);
        $math = app(FinanceMath::class);
        $l = app(FinanceLedger::class);
        $rateId = isset($data['exchange_rate_id']) ? (int) $data['exchange_rate_id'] : null;
        $rate = app(FinanceRates::class)->rate($b, $loan->currency, $rateId, $data['event_on']);
        $prepared = ['agreement' => [...$agreement, 'loan_direction' => 'lend'], 'currency' => $loan->currency, 'exchange_rate_id' => $rateId, 'rate_to_base' => $rate, 'amount' => '0.00', 'lines' => [], 'effects' => []];
        $lines = [];
        if (in_array($kind, ['loan_drawdown', 'loan_opening'], true)) {
            $amount = $math->money($data['amount'] ?? '0');
            abort_unless(! $dated->lending_suspended, 422, 'New lending is suspended after a write-off. A separately reviewed agreement must authorise any resumption.');
            abort_unless($amount->isGreaterThan(0) && ! $amount->plus($dated->revolving ? $state['principal'] : $state['drawn'])->isGreaterThan((string) $dated->limit_amount) && $data['event_on'] <= $dated->matures_on->toDateString(), 422, 'Check the agreed lending limit, actual positive principal and maturity.');
            $base = $math->base($amount, $rate);
            $prepared['amount'] = (string) $amount;
            $lines[] = $l->line('loans_receivable', (string) $amount, '0', (string) $base, '0', 'Actual principal owed to the business');
            if ($kind === 'loan_opening') {
                abort_unless($state['entries']->isEmpty(), 422, 'A verified opening must be the first loan event.');
                $lines[] = $l->line('opening_equity', '0', (string) $amount, '0', (string) $base, 'Verified historical lending position; no invented payment or income');
            } else {
                $cash = app(FinanceRegisterPostings::class)->cash($b, $loan->currency, $data);
                $basis = app(FinanceWorkflows::class)->accountBasis($b->id, $cash->id, $data['event_on']);
                abort_unless(! $amount->isGreaterThan($basis['units']), 422, 'The business cannot lend more actual money than is posted in this account.');
                $lines[] = $l->line($cash->system_key, '0', (string) $amount, '0', (string) $base, 'Actual money handed to borrower');
                $prepared = [...$prepared, 'cash_account_id' => $cash->id, 'cash_direction' => 'out', 'cash_amount' => (string) $amount];
            }
        } elseif (in_array($kind, ['loan_interest', 'loan_fee_accrual'], true)) {
            abort_unless(($data['fee_policy'] ?? 'expense') === 'expense', 422, 'Lending fees require actual earned-fee recognition; deferred borrowing costs belong to a borrowing agreement.');
            $amount = $math->money($data['amount'] ?? '0');
            abort_unless($amount->isGreaterThan(0), 422, 'Enter an agreed positive earned charge.');
            $base = (string) $math->base($amount, $rate);
            $component = $kind === 'loan_interest' ? 'interest' : 'fees';
            $lines = [$l->line(self::ACCOUNTS[$component], (string) $amount, '0', $base, '0', 'Agreed earned '.$component.' receivable'), $l->line($component === 'interest' ? 'loan_interest_income' : 'loan_fee_income', '0', (string) $amount, '0', $base, 'Recognise earned lending income once')];
            $prepared['amount'] = (string) $amount;
            $prepared['details'] = ['fee_policy' => 'expense', 'recognition' => 'earned_receivable', 'due_on' => $data['due_on'] ?? null];
        } elseif ($kind === 'loan_repayment') {
            $total = BigDecimal::zero();
            $components = [];
            $baseCredits = BigDecimal::zero();
            foreach (self::ACCOUNTS as $component => $key) {
                $amount = $math->money($data[$component] ?? '0');
                abort_unless(! $amount->isLessThan(0) && ! $amount->isGreaterThan($state[$component]), 422, 'Receive only the principal, interest and earned fees actually owed.');
                $components[$component] = (string) $amount;
                $total = $total->plus($amount);
                if ($amount->isZero()) {
                    continue;
                } $carry = $this->share($state[$component.'_base'], $amount, $state[$component]);
                $baseCredits = $baseCredits->plus($carry);
                $lines[] = $l->line($key, '0', (string) $amount, '0', (string) $carry, 'Borrower repaid '.$component);
                $released = $this->share($state['unreal'][$component], $amount, $state[$component]);
                $prepared['effects'][$component.'_unreal'] = (string) $released->negated();
                if (! $released->isZero()) {
                    $value = (string) $released->abs();
                    $gain = $released->isGreaterThan(0);
                    $lines[] = $l->line($gain ? 'unrealized_fx_gain' : 'fx_loss', '0', '0', $value, '0', 'Release settled loan FX');
                    $lines[] = $l->line($gain ? 'fx_gain' : 'unrealized_fx_loss', '0', '0', '0', $value, 'Realise only repaid loan FX');
                }
            }
            abort_unless($total->isGreaterThan(0), 422, 'Enter a positive actual repayment.');
            $cash = app(FinanceRegisterPostings::class)->cash($b, $loan->currency, $data);
            $base = $math->base($total, $rate);
            $lines[] = $l->line($cash->system_key, (string) $total, '0', (string) $base, '0', 'Actual borrower repayment received');
            $difference = $base->minus($baseCredits);
            if (! $difference->isZero()) {
                $value = (string) $difference->abs();
                $lines[] = $l->line($difference->isGreaterThan(0) ? 'fx_gain' : 'fx_loss', '0', '0', $difference->isLessThan(0) ? $value : '0', $difference->isGreaterThan(0) ? $value : '0', 'Loan receipt exchange difference');
            }
            if (! empty($data['instalment_id'])) {
                $instalment = LoanInstalment::query()->where('business_id', $b->id)->where('loan_id', $loan->id)->where('status', 'active')->findOrFail($data['instalment_id']);
                $remaining = app(LoanRegister::class)->instalmentBalances($instalment);
                foreach ($components as $component => $value) {
                    abort_unless(! BigDecimal::of($value)->isGreaterThan($remaining[$component]), 422, 'This repayment exceeds the remaining agreed instalment.');
                } $prepared['instalment_id'] = $instalment->id;
            }
            $prepared = [...$prepared, 'amount' => (string) $total, 'cash_account_id' => $cash->id, 'cash_direction' => 'in', 'cash_amount' => (string) $total, 'details' => [...$components, 'interest_treatment' => 'accrued', 'fees_treatment' => 'accrued']];
        } elseif ($kind === 'loan_capitalise_interest') {
            $amount = $math->money($data['amount'] ?? '0');
            abort_unless($amount->isGreaterThan(0) && ! $amount->isGreaterThan($state['interest']), 422, 'Capitalise only agreed existing accrued interest.');
            $base = (string) $this->share($state['interest_base'], $amount, $state['interest']);
            $lines = [$l->line('loans_receivable', (string) $amount, '0', $base, '0', 'Agreed interest added to loan principal'), $l->line('loan_interest_receivable', '0', (string) $amount, '0', $base, 'Move existing interest; no new income or cash')];
            $unreal = $this->share($state['unreal']['interest'], $amount, $state['interest']);
            $prepared['effects'] = ['principal_unreal' => (string) $unreal, 'interest_unreal' => (string) $unreal->negated()];
            $prepared['amount'] = (string) $amount;
        } elseif ($kind === 'loan_revaluation') {
            abort_unless($loan->currency !== $b->base_currency, 422, 'Only a foreign lending balance needs currency revaluation.');
            $gain = BigDecimal::zero();
            $total = BigDecimal::zero();
            foreach (self::ACCOUNTS as $component => $key) {
                $total = $total->plus($state[$component]);
                $change = $math->base($state[$component], $rate)->minus($state[$component.'_base']);
                $prepared['effects'][$component.'_unreal'] = (string) $change;
                if (! $change->isZero()) {
                    $value = (string) $change->abs();
                    $lines[] = $l->line($key, '0', '0', $change->isGreaterThan(0) ? $value : '0', $change->isLessThan(0) ? $value : '0', 'Review foreign lending '.$component);
                    $gain = $gain->plus($change);
                }
            }
            abort_unless($lines !== [] && $total->isGreaterThan(0), 422, 'No outstanding foreign lending value changes at this rate.');
            if (! $gain->isZero()) {
                $value = (string) $gain->abs();
                $lines[] = $l->line($gain->isGreaterThan(0) ? 'unrealized_fx_gain' : 'unrealized_fx_loss', '0', '0', $gain->isLessThan(0) ? $value : '0', $gain->isGreaterThan(0) ? $value : '0', 'Unrealised lending translation');
            } $prepared['amount'] = (string) $total;
        } else {
            abort(422, 'Choose a supported lending task.');
        }
        $prepared['lines'] = $lines;

        return $prepared;
    }
}
