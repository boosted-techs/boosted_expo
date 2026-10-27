<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceRegisterEntry;
use App\Models\LoanFacility;
use App\Models\LoanInstalment;
use App\Support\BusinessCurrencies;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class LoanRegister
{
    public const KINDS = ['loan_drawdown' => 'Record a loan drawdown', 'loan_opening' => 'Record an existing loan opening', 'loan_interest' => 'Accrue agreed interest', 'loan_repayment' => 'Record a loan repayment', 'loan_revaluation' => 'Revalue the loan at an approved rate', 'register_reversal' => 'Correct the latest posted entry'];

    public function create(Business $business, array $data, int $actor): LoanFacility
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            abort_unless(in_array($data['currency'], app(BusinessCurrencies::class)->enabled($business), true), 422, 'Enable the loan currency first.');
            abort_unless(! LoanFacility::query()->where('business_id', $business->id)->where('reference', $data['reference'])->exists(), 422, 'This facility reference is already in use.');
            $loan = LoanFacility::create([...$data, 'business_id' => $business->id, 'created_by' => $actor, 'status' => 'active']);
            app(PlatformAccess::class)->audit($business->id, $actor, 'loan.registered', 'Registered an agreed borrowing facility.', [], ['loan_id' => $loan->id, 'currency' => $loan->currency, 'agreement_reference' => $loan->agreement_reference]);

            return $loan;
        }, 3);
    }

    public function account(LoanFacility $loan): string
    {
        return $loan->lender_kind === 'owner' ? 'owner_borrowing' : 'loan_principal';
    }

    public function state(LoanFacility $loan, ?array $position = null): array
    {
        $p = $position ?? app(FinanceRegisterPostings::class)->position($loan);
        $key = $this->account($loan);
        $principal = ($p['balances'][$key]['units'] ?? BigDecimal::zero())->negated();
        $principalBase = ($p['balances'][$key]['base'] ?? BigDecimal::zero())->negated();
        $interest = ($p['balances']['loan_interest_payable']['units'] ?? BigDecimal::zero())->negated();
        $interestBase = ($p['balances']['loan_interest_payable']['base'] ?? BigDecimal::zero())->negated();
        $unreal = ['principal' => BigDecimal::zero(), 'interest' => BigDecimal::zero()];
        $drawn = BigDecimal::zero();
        foreach ($p['entries'] as $entry) {
            foreach ($unreal as $component => $value) {
                $unreal[$component] = $value->plus((string) ($entry->data_snapshot['effects'][$component.'_unreal'] ?? '0'));
            }
            if ($entry->posting_status === 'posted' && in_array($entry->kind, ['loan_drawdown', 'loan_opening'], true)) {
                $drawn = $drawn->plus((string) $entry->amount);
            }
        }

        return [...$p, 'principal' => $principal, 'principal_base' => $principalBase, 'interest' => $interest, 'interest_base' => $interestBase, 'unreal' => $unreal, 'drawn' => $drawn];
    }

    public function instalmentBalances(LoanInstalment $instalment, bool $includePending = true): array
    {
        $balances = ['principal' => BigDecimal::of((string) $instalment->principal), 'interest' => BigDecimal::of((string) $instalment->interest), 'fees' => BigDecimal::of((string) $instalment->fees)];
        foreach (FinanceRegisterEntry::query()->where('business_id', $instalment->business_id)->where('instalment_id', $instalment->id)->whereIn('posting_status', $includePending ? ['not_posted', 'posted'] : ['posted'])->where('kind', 'loan_repayment')->get() as $payment) {
            foreach ($balances as $key => $value) {
                $balances[$key] = $value->minus((string) ($payment->data_snapshot['details'][$key] ?? '0'));
            }
        }

        return $balances;
    }

    public function schedule(Business $business, LoanFacility $loan, array $data, int $actor): LoanInstalment
    {
        return DB::transaction(function () use ($business, $loan, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $loan = LoanFacility::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($loan->id);
            abort_unless($loan->status === 'active', 422, 'This loan facility is closed.');
            abort_unless($data['due_on'] >= $loan->starts_on->toDateString() && $data['due_on'] <= $loan->matures_on->toDateString(), 422, 'The instalment must fall within the agreed facility term.');
            $math = app(FinanceMath::class);
            $total = $math->sum([(string) $data['principal'], (string) $data['interest'], (string) $data['fees']]);
            abort_unless($total->isGreaterThan(0), 422, 'Enter at least one positive instalment component.');
            $existing = LoanInstalment::query()->where('business_id', $business->id)->where('loan_id', $loan->id)->where('reference', $data['reference'])->first();
            if ($existing) {
                abort_unless($existing->due_on->toDateString() === $data['due_on'] && BigDecimal::of((string) $existing->principal)->isEqualTo((string) $data['principal']) && BigDecimal::of((string) $existing->interest)->isEqualTo((string) $data['interest']) && BigDecimal::of((string) $existing->fees)->isEqualTo((string) $data['fees']), 422, 'This instalment reference already has different terms.');

                return $existing;
            }
            $planned = BigDecimal::of((string) $data['principal']);
            foreach (LoanInstalment::query()->where('business_id', $business->id)->where('loan_id', $loan->id)->where('status', 'active')->get() as $instalment) {
                $planned = $planned->plus($this->instalmentBalances($instalment, false)['principal']);
            }
            $state = $this->state($loan);
            $capacity = $loan->revolving ? BigDecimal::of((string) $loan->limit_amount) : BigDecimal::of((string) $loan->limit_amount)->minus($state['drawn'])->plus($state['principal']);
            abort_unless(! $planned->isGreaterThan($capacity), 422, 'Scheduled unpaid principal exceeds outstanding and undrawn facility capacity.');

            return LoanInstalment::create([...$data, 'business_id' => $business->id, 'loan_id' => $loan->id, 'created_by' => $actor]);
        }, 3);
    }

    public function cancelInstalment(LoanInstalment $instalment, string $reason, int $actor): void
    {
        DB::transaction(function () use ($instalment, $reason, $actor) {
            app(OrderOperations::class)->lockForWork($instalment->business_id, 'finance');
            $instalment = LoanInstalment::query()->where('business_id', $instalment->business_id)->lockForUpdate()->findOrFail($instalment->id);
            abort_unless($instalment->status === 'active', 422, 'This schedule row is already cancelled.');
            abort_if(FinanceRegisterEntry::query()->where('business_id', $instalment->business_id)->where('loan_id', $instalment->loan_id)->where('posting_status', 'not_posted')->exists(), 422, 'Complete or withdraw pending loan transactions before cancelling a plan.');
            $instalment->update(['status' => 'cancelled', 'cancelled_by' => $actor, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            app(PlatformAccess::class)->audit($instalment->business_id, $actor, 'loan.schedule_cancelled', $reason, [], ['instalment_id' => $instalment->id, 'loan_id' => $instalment->loan_id, 'unpaid_components' => array_map(fn ($v) => (string) $v, $this->instalmentBalances($instalment))]);
        }, 3);
    }

    private function portion(BigDecimal $value, BigDecimal $amount, BigDecimal $remaining): BigDecimal
    {
        return $amount->isEqualTo($remaining) ? $value : app(FinanceMath::class)->money($value->multipliedBy($amount)->dividedBy($remaining, 14, RoundingMode::HalfUp));
    }

    public function financial(Business $business, LoanFacility $loan, string $kind, array $data, array $position): array
    {
        abort_unless(isset(self::KINDS[$kind]) && $loan->status === 'active', 422, 'Choose a transaction for an active borrowing facility.');
        abort_unless($data['event_on'] >= $loan->starts_on->toDateString(), 422, 'The event cannot precede the facility agreement.');
        $state = $this->state($loan, $position);
        $key = $this->account($loan);
        $math = app(FinanceMath::class);
        $ledger = app(FinanceLedger::class);
        $rateId = isset($data['exchange_rate_id']) ? (int) $data['exchange_rate_id'] : null;
        $rate = app(FinanceRates::class)->rate($business, $loan->currency, $rateId, $data['event_on']);
        $prepared = ['currency' => $loan->currency, 'exchange_rate_id' => $rateId, 'rate_to_base' => $rate, 'amount' => '0.00', 'lines' => [], 'effects' => []];
        $lines = [];
        if (in_array($kind, ['loan_drawdown', 'loan_opening'], true)) {
            $amount = $math->money($data['amount'] ?? '0');
            abort_unless($amount->isGreaterThan(0), 422, 'Enter positive drawn principal.');
            abort_unless(! $amount->plus($loan->revolving ? $state['principal'] : $state['drawn'])->isGreaterThan((string) $loan->limit_amount), 422, 'This drawdown exceeds the agreed facility limit.');
            abort_unless($data['event_on'] <= $loan->matures_on->toDateString(), 422, 'A drawdown cannot be recorded after facility maturity.');
            $base = $math->base($amount, $rate);
            $prepared['amount'] = (string) $amount;
            if ($kind === 'loan_opening') {
                abort_unless($state['entries']->isEmpty(), 422, 'An existing loan opening must be the first financial event.');
                $lines[] = $ledger->line('opening_equity', (string) $amount, '0', (string) $base, '0', 'Verified existing borrowing opening');
            } else {
                $cash = app(FinanceRegisterPostings::class)->cash($business, $loan->currency, $data);
                $prepared = [...$prepared, 'cash_account_id' => $cash->id, 'cash_direction' => 'in', 'cash_amount' => (string) $amount];
                $lines[] = $ledger->line($cash->system_key, (string) $amount, '0', (string) $base, '0', 'Actual loan drawdown received');
            }
            $lines[] = $ledger->line($key, '0', (string) $amount, '0', (string) $base, 'Borrowing principal, not sales income');
        } elseif ($kind === 'loan_interest') {
            abort_unless($state['drawn']->isGreaterThan(0), 422, 'Post drawn or opening principal before recognising interest.');
            $amount = $math->money($data['amount'] ?? '0');
            abort_unless($amount->isGreaterThan(0), 422, 'Enter agreed interest from the lender evidence.');
            $base = $math->base($amount, $rate);
            $prepared['amount'] = (string) $amount;
            $lines = [$ledger->line('loan_interest_expense', (string) $amount, '0', (string) $base, '0', 'Interest expense from agreed lender terms'), $ledger->line('loan_interest_payable', '0', (string) $amount, '0', (string) $base, 'Accrued unpaid interest')];
        } elseif ($kind === 'loan_repayment') {
            $principal = $math->money($data['principal'] ?? '0');
            $interest = $math->money($data['interest'] ?? '0');
            $fees = $math->money($data['fees'] ?? '0');
            $total = $principal->plus($interest)->plus($fees);
            abort_unless($total->isGreaterThan(0) && ! $principal->isLessThan(0) && ! $interest->isLessThan(0) && ! $fees->isLessThan(0) && ! $principal->isGreaterThan($state['principal']), 422, 'Enter valid components within the posted principal balance.');
            $accrued = ($data['interest_treatment'] ?? 'paid') === 'accrued';
            if ($accrued) {
                abort_unless(! $interest->isGreaterThan($state['interest']), 422, 'Interest payment exceeds the accrued unpaid interest.');
            }
            $cash = app(FinanceRegisterPostings::class)->cash($business, $loan->currency, $data);
            $cashBase = $math->base($total, $rate);
            $debitBase = BigDecimal::zero();
            $reclass = BigDecimal::zero();
            $effects = [];
            if ($principal->isGreaterThan(0)) {
                $portion = $this->portion($state['principal_base'], $principal, $state['principal']);
                $u = $this->portion($state['unreal']['principal'], $principal, $state['principal']);
                $lines[] = $ledger->line($key, (string) $principal, '0', (string) $portion, '0', 'Principal repaid');
                $debitBase = $debitBase->plus($portion);
                $reclass = $reclass->plus($u);
                $effects['principal_unreal'] = (string) $u->negated();
            }
            if ($interest->isGreaterThan(0)) {
                $portion = $accrued ? $this->portion($state['interest_base'], $interest, $state['interest']) : $math->base($interest, $rate);
                $lines[] = $ledger->line($accrued ? 'loan_interest_payable' : 'loan_interest_expense', (string) $interest, '0', (string) $portion, '0', $accrued ? 'Previously accrued interest settled' : 'Interest paid as incurred under recorded policy');
                $debitBase = $debitBase->plus($portion);
                if ($accrued) {
                    $u = $this->portion($state['unreal']['interest'], $interest, $state['interest']);
                    $reclass = $reclass->plus($u);
                    $effects['interest_unreal'] = (string) $u->negated();
                }
            }
            if ($fees->isGreaterThan(0)) {
                $portion = $math->base($fees, $rate);
                $lines[] = $ledger->line('loan_fee_expense', (string) $fees, '0', (string) $portion, '0', 'Explicit lender fees expensed as incurred');
                $debitBase = $debitBase->plus($portion);
            }
            $lines[] = $ledger->line($cash->system_key, '0', (string) $total, '0', (string) $cashBase, 'Actual principal, interest and fee cash paid');
            $difference = $debitBase->minus($cashBase);
            if ($difference->isGreaterThan(0)) {
                $lines[] = $ledger->line('fx_gain', '0', '0', '0', (string) $difference, 'Realised borrowing FX gain');
            } elseif ($difference->isLessThan(0)) {
                $lines[] = $ledger->line('fx_loss', '0', '0', (string) $difference->negated(), '0', 'Realised borrowing FX loss');
            }
            if ($reclass->isGreaterThan(0)) {
                $lines[] = $ledger->line('unrealized_fx_gain', '0', '0', (string) $reclass, '0', 'Release settled unrealised borrowing gain');
                $lines[] = $ledger->line('fx_gain', '0', '0', '0', (string) $reclass, 'Realised previously accrued gain');
            } elseif ($reclass->isLessThan(0)) {
                $lines[] = $ledger->line('unrealized_fx_loss', '0', '0', '0', (string) $reclass->negated(), 'Release settled unrealised borrowing loss');
                $lines[] = $ledger->line('fx_loss', '0', '0', (string) $reclass->negated(), '0', 'Realised previously accrued loss');
            }
            $prepared = [...$prepared, 'amount' => (string) $total, 'cash_account_id' => $cash->id, 'cash_direction' => 'out', 'cash_amount' => (string) $total, 'effects' => $effects, 'details' => ['principal' => (string) $principal, 'interest' => (string) $interest, 'fees' => (string) $fees, 'interest_treatment' => $accrued ? 'accrued' : 'paid_as_incurred', 'fee_policy' => 'expense_as_incurred']];
            if (! empty($data['instalment_id'])) {
                $instalment = LoanInstalment::query()->where('business_id', $business->id)->where('loan_id', $loan->id)->findOrFail($data['instalment_id']);
                $remaining = $this->instalmentBalances($instalment);
                abort_unless($instalment->status === 'active', 422, 'This instalment plan was cancelled; select its replacement or an unscheduled payment.');
                foreach (['principal' => $principal, 'interest' => $interest, 'fees' => $fees] as $component => $paid) {
                    abort_unless(! $paid->isGreaterThan($remaining[$component]), 422, 'This payment exceeds the instalment component still available.');
                }
                $prepared['instalment_id'] = $instalment->id;
            }
        } elseif ($kind === 'loan_revaluation') {
            abort_unless($loan->currency !== $business->base_currency, 422, 'Base-currency loans do not need FX revaluation.');
            $netChange = BigDecimal::zero();
            $effects = [];
            foreach (['principal' => $key, 'interest' => 'loan_interest_payable'] as $component => $account) {
                $units = $state[$component];
                if (! $units->isGreaterThan(0)) {
                    continue;
                }
                $target = $math->base($units, $rate);
                $delta = $target->minus($state[$component.'_base']);
                $netChange = $netChange->plus($delta);
                if ($delta->isGreaterThan(0)) {
                    $lines[] = $ledger->line($account, '0', '0', '0', (string) $delta, 'Approved '.$component.' carrying-value increase');
                } elseif ($delta->isLessThan(0)) {
                    $lines[] = $ledger->line($account, '0', '0', (string) $delta->negated(), '0', 'Approved '.$component.' carrying-value decrease');
                }
                $effects[$component.'_unreal'] = (string) $delta->negated();
            }
            abort_unless(count($lines) > 0, 422, 'No loan carrying-value change exists at this rate.');
            if ($netChange->isGreaterThan(0)) {
                $lines[] = $ledger->line('unrealized_fx_loss', '0', '0', (string) $netChange, '0', 'Unrealised borrowing FX loss');
            } elseif ($netChange->isLessThan(0)) {
                $lines[] = $ledger->line('unrealized_fx_gain', '0', '0', '0', (string) $netChange->negated(), 'Unrealised borrowing FX gain');
            }
            $prepared['amount'] = (string) $netChange->abs();
            $prepared['effects'] = $effects;
        }
        $prepared['lines'] = $lines;

        return $prepared;
    }
}
